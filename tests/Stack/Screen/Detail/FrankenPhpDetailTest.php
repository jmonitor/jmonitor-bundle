<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\FrankenPhpDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\WebServerPayloads;
use PHPUnit\Framework\TestCase;

final class FrankenPhpDetailTest extends TestCase
{
    use DescribesSections;

    public function testFrankenPhpShowsItsThreadsItsServerAndItsWorkers(): void
    {
        $snapshot = Snapshots::of(Component::FrankenPhp, ['frankenphp' => WebServerPayloads::frankenphpWorker(), 'frankenphp.version' => ['version' => '1.13.0']]);

        $blocks = (new FrankenPhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['gauges', 'Threads', ['Busy' => self::nb('25 %')]],
            ['facts', 'Server', ['Version' => '1.13.0', 'Mode' => 'worker', 'Threads' => self::nb('1 / 4'), 'Queue' => '0']],
            ['table', 'Workers', [
                '/app/public/index.php | 2 | 8 | — | ' . self::nb('12.6 ms') . ' | 0 | 1 | 3',
                'api | 4 | 4 | — | ' . self::nb('20.0 ms') . ' | 2 | 0 | 0',
            ]],
        ], self::describe($blocks));
        self::assertSame('12 threads run the workers', self::hints($blocks)['Threads / Busy']);

        $table = $blocks[2]->content;
        self::assertInstanceOf(Table::class, $table);
        self::assertSame(
            ['worker' => 'Worker', 'busy' => 'Busy', 'ready' => 'Ready', 'requests' => 'Requests', 'time' => 'Avg time', 'queue' => 'Queue', 'crashes' => 'Crashes', 'restarts' => 'Restarts'],
            array_combine(array_map(static fn(Column $column): string => $column->id, $table->columns), array_map(static fn(Column $column): string => $column->label, $table->columns)),
        );
        self::assertSame([null], array_unique(array_map(static fn(Column $column): ?string => $column->sort, $table->columns)));
        self::assertSame([['data-jm-worker' => '/app/public/index.php'], ['data-jm-worker' => 'api']], array_map(static fn(Row $row): array => $row->attributes, $table->rows));
        self::assertSame('worker', $table->noun);
    }

    public function testTheRequestsOfAWorkerAreARate(): void
    {
        $previous = WebServerPayloads::frankenphpWorker();
        $previous['workers'][0]['worker_request_count'] = 9_400;
        $previous['workers'][0]['worker_request_time'] = 119.5;
        $delta = Snapshots::delta($previous, WebServerPayloads::frankenphpWorker());
        $snapshot = Snapshots::of(Component::FrankenPhp, ['frankenphp' => WebServerPayloads::frankenphpWorker()], deltas: ['frankenphp' => $delta]);

        $rows = self::describe((new FrankenPhpDetail())->sections(self::instance($snapshot), [], Snapshots::NOW))[2][2];

        self::assertSame('/app/public/index.php | 2 | 8 | ' . self::nb('10.0 req/s') . ' | ' . self::nb('10.0 ms') . ' | 0 | 1 | 3', $rows[0]);
    }

    public function testARaisedCheckMarksTheQueue(): void
    {
        $snapshot = Snapshots::of(Component::FrankenPhp, ['frankenphp' => ['queue_depth' => 2] + WebServerPayloads::frankenphpClassic()]);

        self::assertSame('2 [Warning]', self::describe((new FrankenPhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW))[1][2]['Queue']);
    }

    public function testClassicModeHasNoWorkersTable(): void
    {
        $snapshot = Snapshots::of(Component::FrankenPhp, ['frankenphp' => WebServerPayloads::frankenphpClassic(), 'frankenphp.version' => ['version' => '1.13.0']]);

        $blocks = (new FrankenPhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['gauges', 'Threads', ['Busy' => self::nb('18.7 %')]],
            ['facts', 'Server', ['Version' => '1.13.0', 'Mode' => 'classic', 'Threads' => self::nb('3 / 16'), 'Queue' => '0']],
        ], self::describe($blocks));
        self::assertNull(self::hints($blocks)['Threads / Busy']);
    }

    public function testAVersionThatCouldNotBeReadIsADash(): void
    {
        $snapshot = Snapshots::of(Component::FrankenPhp, ['frankenphp' => WebServerPayloads::frankenphpClassic()], ['frankenphp.version' => ResultState::Failed]);

        $blocks = (new FrankenPhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(['Threads', 'Server'], self::titles($blocks));
        self::assertSame('—', self::describe($blocks)[1][2]['Version']);
    }

    public function testASectionSaysReadingWhileItsCollectorWasNeverRead(): void
    {
        $snapshot = Snapshots::of(Component::FrankenPhp, [], ['frankenphp' => ResultState::Pending, 'frankenphp.version' => ResultState::Pending]);

        self::assertSame([['reading', 'gauges']], self::describe((new FrankenPhpDetail())->sections(self::instance($snapshot), [], Snapshots::NOW)));
        self::assertSame([], (new FrankenPhpDetail())->sections(self::instance($snapshot), [], Snapshots::NOW, false));
    }

    public function testAnOutdatedValueSaysItsAge(): void
    {
        $snapshot = Snapshots::of(Component::FrankenPhp, ['frankenphp' => WebServerPayloads::frankenphpWorker()], ['frankenphp' => ResultState::Stale]);

        $blocks = (new FrankenPhpDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['notice', 'info', 'Read ' . self::nb('1 h') . ' ago', ''], self::describe($blocks)[0]);
        self::assertSame(['Threads', 'Server', 'Workers'], array_slice(self::titles($blocks), 1));
    }

    public function testAStatusPageThatCouldNotBeReadLeavesItsReasonToTheCard(): void
    {
        $snapshot = Snapshots::of(Component::FrankenPhp, ['frankenphp.version' => ['version' => '1.13.0']], ['frankenphp' => ResultState::Failed]);

        self::assertSame([], (new FrankenPhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW));
    }

    public function testAnEmptySnapshotRendersWithoutWarning(): void
    {
        [$empty, $odd] = self::withoutWarnings(static fn(): array => [
            (new FrankenPhpDetail())->sections(self::instance(Snapshots::of(Component::FrankenPhp, ['frankenphp' => []])), [], Snapshots::NOW),
            (new FrankenPhpDetail())->sections(self::instance(Snapshots::of(Component::FrankenPhp, [
                'frankenphp' => ['mode' => 'worker', 'busy_threads' => 'many', 'total_threads' => [16], 'queue_depth' => null, 'workers' => [
                    'nope',
                    ['name' => ['x'], 'busy_workers' => 'two', 'worker_request_time' => 'slow'],
                ]],
                'frankenphp.version' => ['version' => ['1.13.0']],
            ])), [], Snapshots::NOW),
        ]);

        self::assertSame([
            ['gauges', 'Threads', ['Busy' => '']],
            ['facts', 'Server', ['Version' => '—', 'Mode' => '—', 'Threads' => '—', 'Queue' => '—']],
        ], self::describe($empty));
        self::assertSame([
            ['gauges', 'Threads', ['Busy' => '']],
            ['facts', 'Server', ['Version' => '—', 'Mode' => 'worker', 'Threads' => '—', 'Queue' => '—']],
            ['table', 'Workers', [' | — | — | — | — | — | — | —']],
        ], self::describe($odd));
    }
}
