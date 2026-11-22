<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ServerDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;

final class ServerDetailTest extends TestCase
{
    use DescribesSections;

    public function testTheServerShowsItsUsageItsLoadAndItsSystem(): void
    {
        $snapshot = Snapshots::of(Component::System, ['system' => Snapshots::system()]);

        $blocks = (new ServerDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['gauges', 'Usage', ['Load' => self::nb('62 %'), 'Memory' => self::nb('75 %'), 'Disk' => self::nb('75 %')]],
            ['facts', 'Load', [self::nb('1 min') => '2.48', self::nb('5 min') => '1.10', self::nb('15 min') => '0.90', 'CPU cores' => '4']],
            ['facts', 'System', ['OS' => 'Debian GNU/Linux 12 (bookworm)', 'Uptime' => self::nb('1 d'), 'Hostname' => 'web-1']],
        ], self::describe($blocks));
        self::assertSame([
            'Usage / Load' => null,
            'Usage / Memory' => '5.6 GB of 7.5 GB',
            'Usage / Disk' => '70 GB of 93 GB',
            'Load / 1 min' => null,
            'Load / 5 min' => null,
            'Load / 15 min' => null,
            'Load / CPU cores' => null,
            'System / OS' => null,
            'System / Uptime' => null,
            'System / Hostname' => null,
        ], array_combine(
            array_map(static fn(string $label): string => str_replace(self::NBSP, ' ', $label), array_keys(self::hints($blocks))),
            array_map(static fn(?string $hint): ?string => $hint === null ? null : str_replace(self::NBSP, ' ', $hint), self::hints($blocks)),
        ));
    }

    public function testInAContainerTheCgroupLimitsAreShownAndTheLoadIsExplained(): void
    {
        $system = Snapshots::system();
        $system['cgroup'] = ['memory_limit' => 2_147_483_648, 'cpu_quota' => 1.5];
        $system['os']['uptime'] = 3 * 86_400 + 4 * 3_600 + 120;
        $snapshot = Snapshots::of(Component::System, ['system' => $system]);

        $blocks = (new ServerDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(['facts', 'Load', [self::nb('1 min') => '2.48', self::nb('5 min') => '1.10', self::nb('15 min') => '0.90', 'CPU cores' => '4', 'CPU quota' => self::nb('1.5 CPUs')]], self::describe($blocks)[1]);
        self::assertSame(['facts', 'System', ['OS' => 'Debian GNU/Linux 12 (bookworm)', 'Uptime' => self::nb('3 d 4 h'), 'Hostname' => 'web-1', 'Memory limit' => self::nb('2 GB')]], self::describe($blocks)[2]);
        self::assertSame('Load is measured on the host node', self::hints($blocks)['Usage / Load']);
    }

    public function testARaisedCheckMarksItsGauge(): void
    {
        $system = Snapshots::system();
        $system['disk']['free'] = 3_000_000_000;
        $system['ram']['available'] = 1_000_000_000;
        $system['cpu']['load'] = 130;
        $snapshot = Snapshots::of(Component::System, ['system' => $system]);

        $blocks = (new ServerDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(
            ['gauges', 'Usage', ['Load' => self::nb('130 %') . ' [Warning]', 'Memory' => self::nb('87.5 %') . ' [Warning]', 'Disk' => self::nb('97 %') . ' [Critical]']],
            self::describe($blocks)[0],
        );

        $blocks = (new ServerDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['gauges', 'Usage', ['Load' => self::nb('130 %'), 'Memory' => self::nb('87.5 %'), 'Disk' => self::nb('97 %')]], self::describe($blocks)[0]);
    }

    public function testAGaugeNeverShowsAThresholdItDidNotReach(): void
    {
        foreach ([154 => self::nb('84.6 %'), 54 => self::nb('94.6 %') . ' [Warning]'] as $available => $shown) {
            $system = Snapshots::system();
            $system['ram'] = ['total' => 1_000, 'available' => $available];
            $snapshot = Snapshots::of(Component::System, ['system' => $system]);

            self::assertSame($shown, self::describe((new ServerDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW))[0][2]['Memory']);
        }
    }

    public function testASectionSaysReadingWhileItsCollectorWasNeverRead(): void
    {
        foreach ([ResultState::Pending, ResultState::Busy] as $state) {
            $snapshot = Snapshots::of(Component::System, [], ['system' => $state]);

            self::assertSame([['reading', 'gauges']], self::describe((new ServerDetail())->sections(self::instance($snapshot), [], Snapshots::NOW)));
            self::assertSame([], (new ServerDetail())->sections(self::instance($snapshot), [], Snapshots::NOW, false));
        }

        $blocks = (new ServerDetail())->sections(self::instance(new ComponentSnapshot(Component::System, [])), [], Snapshots::NOW);

        self::assertSame([['reading', 'gauges']], self::describe($blocks));
        self::assertTrue($blocks[0]->reading);
    }

    public function testAnOutdatedValueSaysItsAge(): void
    {
        $snapshot = new ComponentSnapshot(Component::System, [
            'system' => new CollectorResult('system', Snapshots::system(), ResultState::Stale, collectedAt: Snapshots::NOW - 185, expiresAt: Snapshots::NOW - 125),
        ]);

        $blocks = (new ServerDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['notice', 'info', 'Read ' . self::nb('3 min') . ' ago', ''], self::describe($blocks)[0]);
        self::assertSame(['notice: Read ' . self::nb('3 min') . ' ago', 'Usage', 'Load', 'System'], self::titles($blocks));
    }

    public function testAFailedRenewalSaysWhyAndWhenItIsRetried(): void
    {
        $snapshot = new ComponentSnapshot(Component::System, [
            'system' => new CollectorResult('system', Snapshots::system(), ResultState::Stale, 'open_basedir forbids /proc', Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 480),
        ]);

        $blocks = (new ServerDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['notice', 'warn', 'Could not be read again: open_basedir forbids /proc', 'next try in ' . self::nb('8 min')], self::describe($blocks)[0]);
        self::assertSame(['Usage', 'Load', 'System'], array_slice(self::titles($blocks), 1));
    }

    public function testAFailedServerLeavesItsReasonToTheCard(): void
    {
        $failed = Snapshots::of(Component::System, [], ['system' => ResultState::Failed]);

        self::assertSame([], (new ServerDetail())->sections(self::instance($failed), [], Snapshots::NOW));
        self::assertSame([], (new ServerDetail())->sections(new InstanceState(null, null, 'Not on Linux', null), [], Snapshots::NOW));
        self::assertSame([], (new ServerDetail())->sections(self::instance(new ComponentSnapshot(Component::System, [], 'Not on Linux')), [], Snapshots::NOW));
    }

    public function testAnEmptySnapshotRendersWithoutWarning(): void
    {
        [$empty, $odd] = self::withoutWarnings(static fn(): array => [
            (new ServerDetail())->sections(self::instance(Snapshots::of(Component::System, ['system' => []])), [], Snapshots::NOW),
            (new ServerDetail())->sections(self::instance(Snapshots::of(Component::System, ['system' => [
                'cpu' => 'busy',
                'ram' => ['total' => 'lots', 'available' => []],
                'disk' => null,
                'os' => ['pretty_name' => ['Linux'], 'uptime' => -5],
                'hostname' => 42,
                'cgroup' => 'none',
            ]])), [], Snapshots::NOW),
        ]);

        self::assertSame([
            ['gauges', 'Usage', ['Load' => '', 'Memory' => '', 'Disk' => '']],
            ['facts', 'Load', [self::nb('1 min') => '—', self::nb('5 min') => '—', self::nb('15 min') => '—', 'CPU cores' => '—']],
            ['facts', 'System', ['OS' => '—', 'Uptime' => '—', 'Hostname' => '—']],
        ], self::describe($empty));
        self::assertSame(['Usage', 'Load', 'System'], self::titles($odd));
        self::assertSame('42', self::describe($odd)[2][2]['Hostname']);
        self::assertSame('—', self::describe($odd)[2][2]['Uptime']);
    }
}
