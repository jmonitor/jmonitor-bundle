<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ApacheDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\WebServerPayloads;
use PHPUnit\Framework\TestCase;

final class ApacheDetailTest extends TestCase
{
    use DescribesSections;

    public function testApacheShowsItsWorkersTrafficServerAndScoreboard(): void
    {
        $snapshot = Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache(), 'eol.apache-http-server' => WebServerPayloads::cycles()]);

        $blocks = (new ApacheDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['gauges', 'Workers', ['Busy workers' => self::nb('6 %'), 'Used slots' => self::nb('0.7 %')]],
            ['facts', 'Traffic', [
                'Requests per second' => '—',
                'Bytes per second' => '—',
                'Bytes per request' => self::nb('25 KB'),
                'Time per request' => self::nb('40.0 ms'),
            ]],
            ['facts', 'Server', ['Version' => '2.4.62', 'MPM' => 'event', 'Uptime' => self::nb('12 d 1 h')]],
            ['facts', 'Scoreboard', [
                'Waiting for connection' => '47',
                'Reading request' => '1',
                'Sending reply' => '1',
                'Keepalive' => '1',
                'Open slot' => '350',
            ]],
        ], self::describe($blocks));

        $hints = self::hints($blocks);
        self::assertSame(self::nb('3 / 50'), $hints['Workers / Busy workers']);
        self::assertSame(self::nb('3 / 400'), $hints['Workers / Used slots']);
        self::assertSame('at the next refresh', $hints['Traffic / Requests per second']);
        self::assertSame('at the next refresh', $hints['Traffic / Bytes per second']);
        self::assertSame('since server start', $hints['Traffic / Bytes per request']);
        self::assertSame('since server start', $hints['Traffic / Time per request']);
    }

    public function testTheTrafficOverTheLastSamplesSaysItsSpan(): void
    {
        $delta = Snapshots::delta(WebServerPayloads::apache(51_880, 1_325_056_000, 2_077.6), WebServerPayloads::apache());
        $snapshot = Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache()], deltas: ['apache' => $delta]);

        $blocks = (new ApacheDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame([
            'Requests per second' => self::nb('2.0 req/s'),
            'Bytes per second' => self::nb('100 KB') . '/s',
            'Bytes per request' => self::nb('50 KB'),
            'Time per request' => self::nb('20.0 ms'),
        ], self::describe($blocks)[1][2]);

        foreach (['Requests per second', 'Bytes per second', 'Bytes per request', 'Time per request'] as $label) {
            self::assertSame('over the last ' . self::nb('60 s'), self::hints($blocks)['Traffic / ' . $label], $label);
        }
    }

    public function testAGaugeAndItsCheckShowTheSameFigure(): void
    {
        $apache = WebServerPayloads::apache();
        $apache['scoreboard'] = ['_' => 249, 'W' => 2_251];
        $apache['workers'] = ['busy' => 2_251, 'idle' => 0];
        $snapshot = Snapshots::of(Component::Apache, ['apache' => $apache]);
        $findings = self::findings($snapshot);

        $gauges = self::describe((new ApacheDetail())->sections(self::instance($snapshot), $findings, Snapshots::NOW))[0][2];

        self::assertSame(['Busy workers' => self::nb('100 %') . ' [Info]', 'Used slots' => self::nb('90 %') . ' [Warning]'], $gauges);
        self::assertSame(
            'Apache workers take ' . self::nb('90 %') . ' of the slots (2251 of 2500)',
            array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->id === 'apache.workers'))[0]->sentence,
        );
    }

    public function testTheEndOfSupportMarksTheVersion(): void
    {
        $cycles = ['cycles' => ['2.4' => ['eol' => '2025-01-01', 'support' => '2025-01-01']]];
        $snapshot = Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache(), 'eol.apache-http-server' => $cycles]);

        self::assertSame('2.4.62 [End of life]', self::describe((new ApacheDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW))[2][2]['Version']);
    }

    public function testASectionSaysReadingWhileItsCollectorWasNeverRead(): void
    {
        $snapshot = Snapshots::of(Component::Apache, [], ['apache' => ResultState::Busy]);

        self::assertSame([['reading', 'gauges']], self::describe((new ApacheDetail())->sections(self::instance($snapshot), [], Snapshots::NOW)));
        self::assertSame([], (new ApacheDetail())->sections(self::instance($snapshot), [], Snapshots::NOW, false));
    }

    public function testAnOutdatedValueSaysItsAge(): void
    {
        $snapshot = Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache()], ['apache' => ResultState::Stale]);

        $blocks = (new ApacheDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['notice', 'info', 'Read ' . self::nb('1 h') . ' ago', ''], self::describe($blocks)[0]);
        self::assertSame(['Workers', 'Traffic', 'Server', 'Scoreboard'], array_slice(self::titles($blocks), 1));
    }

    public function testAnEmptySnapshotRendersWithoutWarning(): void
    {
        [$empty, $odd] = self::withoutWarnings(static fn(): array => [
            (new ApacheDetail())->sections(self::instance(Snapshots::of(Component::Apache, ['apache' => []])), [], Snapshots::NOW),
            (new ApacheDetail())->sections(self::instance(Snapshots::of(Component::Apache, ['apache' => [
                'server_version' => ['Apache'],
                'uptime' => 'long',
                'workers' => 'some',
                'scoreboard' => ['W' => 'one', '_' => -3],
                'total_accesses' => 0,
                'total_bytes' => 'lots',
            ], 'eol.apache-http-server' => ['cycles' => 'soon']])), [], Snapshots::NOW),
        ]);

        $nothing = [
            ['gauges', 'Workers', ['Busy workers' => '', 'Used slots' => '']],
            ['facts', 'Traffic', ['Requests per second' => '—', 'Bytes per second' => '—', 'Bytes per request' => '—', 'Time per request' => '—']],
            ['facts', 'Server', ['Version' => '—', 'MPM' => '—', 'Uptime' => '—']],
        ];

        self::assertSame($nothing, self::describe($empty));
        self::assertSame($nothing, self::describe($odd));
    }
}
