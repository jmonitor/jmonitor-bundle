<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\Section\TasksSection;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedSchedules;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

final class TasksSectionTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheSectionDeclaresItself(): void
    {
        $screen = new TasksSection(true, new DeclaredTasks());

        static::assertSame('tasks', $screen->id());
        static::assertSame('Scheduled tasks', $screen->title());
        static::assertSame('What Symfony Scheduler starts by the clock.', $screen->summary());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['next' => 'asc', 'task' => 'asc', 'last' => 'desc', 'runs' => 'desc', 'avg' => 'desc', 'max' => 'desc', 'failed' => 'desc'],
            $screen->sorts(),
        );
        foreach (Period::cases() as $period) {
            static::assertSame([
                'title' => 'No scheduled task is declared.',
                'text' => 'Tasks declared in a schedule show up here with their next run, and their runs once a worker consumes the schedule.',
            ], $screen->emptyState($period));
        }
    }

    public function testTheScreenFollowsItsAvailability(): void
    {
        static::assertFalse((new TasksSection(false, new DeclaredTasks()))->isAvailable());
        static::assertTrue((new TasksSection(true, new DeclaredTasks()))->isAvailable());
    }

    public function testTheScreenReadsTheTaskFamilyOnceWithItsSeries(): void
    {
        $screen = new TasksSection(true, new DeclaredTasks());

        foreach (array_keys($screen->sorts()) as $sort) {
            $reader = new FixedReader();
            $screen->build($reader, Period::SixHours, self::NOW, $sort, 'asc', new RaisedChecks([], [], Period::OneHour));

            static::assertCount(1, $reader->queries, $sort);
            $query = $reader->queries[0];
            static::assertSame([Family::Task, Period::SixHours, self::NOW, Metric::TASK_DURATION], [$query->family, $query->period, $query->now, $query->seriesMetric]);
            static::assertSame([], $reader->labelRequests);
        }
    }

    public function testADeclaredTaskThatNeverRan(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . (self::NOW - 600));

        $view = $this->build([], ['default' => [$work]]);
        $table = $view->tables[0];
        $row = $table->rows[0];

        static::assertFalse($view->isEmpty());
        static::assertSame('tasks', $table->id);
        static::assertSame('Tasks', $table->title);
        static::assertSame('task', $table->noun);
        static::assertSame(
            [
                ['task', 'Task', false, 'task'],
                ['schedule', 'Schedule', false, null],
                ['trigger', 'Trigger', false, null],
                ['next', 'Next run', true, 'next'],
                ['last', 'Last run', true, 'last'],
                ['runs', 'Runs', true, 'runs'],
                ['avg', 'Avg', true, 'avg'],
                ['max', 'Max', true, 'max'],
                ['failed', 'Failed', true, 'failed'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertCount(1, $table->rows);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-task' => 'app:work', 'data-jm-runs' => '0'], $row->attributes);
        static::assertSame(
            ['task' => 'app:work', 'schedule' => 'default', 'trigger' => 'every 1 hour', 'next' => 'in 50 min', 'last' => '—', 'runs' => '0', 'avg' => '—', 'max' => '—', 'failed' => '0'],
            self::texts($row),
        );
        static::assertSame('code', $row->cells['task']->kind);
        static::assertSame(['zero', 'zero', 'zero', 'zero', 'zero'], array_map(static fn(string $id): ?string => $row->cells[$id]->tone, ['last', 'runs', 'avg', 'max', 'failed']));
        static::assertNull($row->cells['next']->tone);
    }

    public function testATaskThatRanButIsNoLongerDeclared(): void
    {
        $row = $this->table($this->build([
            self::task('h1', new TaskKey('default', 'gone', 'app:old', 'every 1 day'), runs: 4, totalMs: 200.0, maxMs: 90.0, last: self::NOW - 600),
        ]))->rows[0];

        static::assertSame(['data-jm-task' => 'app:old', 'data-jm-runs' => '4'], $row->attributes);
        static::assertSame(
            ['task' => 'app:old', 'schedule' => 'default', 'trigger' => 'every 1 day', 'next' => '—', 'last' => '10 min ago', 'runs' => '4', 'avg' => '50 ms', 'max' => '90 ms', 'failed' => '0'],
            self::texts($row),
        );
        static::assertSame('zero', $row->cells['next']->tone);
        static::assertNull($row->cells['last']->tone);
        static::assertNull($row->cells['runs']->tone);
    }

    public function testADeclaredTaskAndItsRunsShareOneRowEvenWhenTheirLabelsDiffer(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . (self::NOW - 600));
        $stored = self::task('h1', new TaskKey('default', $work->getId(), 'app:work --before-rename', 'every 1 hour'), runs: 2, totalMs: 30.0, maxMs: 20.0, last: self::NOW - 120);

        $rows = $this->table($this->build([$stored], ['default' => [$work]]))->rows;

        static::assertCount(1, $rows);
        static::assertSame(['data-jm-task' => 'app:work', 'data-jm-runs' => '2'], $rows[0]->attributes);
        static::assertSame(
            ['task' => 'app:work', 'schedule' => 'default', 'trigger' => 'every 1 hour', 'next' => 'in 50 min', 'last' => '2 min ago', 'runs' => '2', 'avg' => '15 ms', 'max' => '20 ms', 'failed' => '0'],
            self::texts($rows[0]),
        );
    }

    public function testTheSameIdInAnotherScheduleIsAnotherTask(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . self::NOW);

        $rows = $this->table($this->build([
            self::task('h1', new TaskKey('reports', $work->getId(), 'app:work', 'every 1 hour'), runs: 1, last: self::NOW - 60),
        ], ['default' => [$work]]))->rows;

        static::assertSame(['default', 'reports'], array_map(static fn(Row $row): string => $row->cells['schedule']->text, $rows));
    }

    public function testALastRunThatFailedSaysSo(): void
    {
        $rows = $this->table($this->build([
            self::task('h1', new TaskKey('default', 'a', 'app:failing', 'every 1 hour'), runs: 3, last: self::NOW - 300, failed: 1, lastFailed: self::NOW - 300),
            self::task('h2', new TaskKey('default', 'b', 'app:recovered', 'every 1 hour'), runs: 3, last: self::NOW - 60, failed: 2, lastFailed: self::NOW - 300),
        ], sort: 'task', direction: 'asc'))->rows;

        static::assertSame(['app:failing', 'app:recovered'], array_map(static fn(Row $row): string => $row->cells['task']->text, $rows));
        static::assertSame(['5 min ago · failed', 'bad'], [$rows[0]->cells['last']->text, $rows[0]->cells['last']->tone]);
        static::assertSame(['1 min ago', null], [$rows[1]->cells['last']->text, $rows[1]->cells['last']->tone]);
        static::assertSame(['1', 'bad'], [$rows[0]->cells['failed']->text, $rows[0]->cells['failed']->tone]);
        static::assertSame(['2', 'bad'], [$rows[1]->cells['failed']->text, $rows[1]->cells['failed']->tone]);
    }

    public function testTheDefaultSortPutsTheNextRunFirstAndTasksWithoutOneLast(): void
    {
        $hourly = RecurringMessage::every('1 hour', new RunCommandMessage('app:hourly'), '@' . (self::NOW - 600));
        $minutely = RecurringMessage::every('1 minute', new RunCommandMessage('app:minutely'), '@' . (self::NOW - 30));
        $ended = RecurringMessage::every('1 hour', new RunCommandMessage('app:ended'), '@' . (self::NOW - 7200), '@' . (self::NOW - 3600));

        $view = $this->build([
            self::task('h1', new TaskKey('default', 'gone', 'app:gone', 'every 1 day'), runs: 1, last: self::NOW - 60),
        ], ['default' => [$hourly, $ended, $minutely]]);

        static::assertSame(['app:minutely', 'app:hourly', 'app:ended', 'app:gone'], $this->labels($view));
        static::assertSame(['in 30 s', 'in 50 min', '—', '—'], array_map(static fn(Row $row): string => $row->cells['next']->text, $this->table($view)->rows));
    }

    public function testEverySortKey(): void
    {
        $alpha = RecurringMessage::every('1 hour', new RunCommandMessage('app:alpha'), '@' . (self::NOW - 600));
        $beta = RecurringMessage::every('1 minute', new RunCommandMessage('app:beta'), '@' . (self::NOW - 30));
        $stored = [
            self::task('h1', new TaskKey('default', $alpha->getId(), 'app:alpha', 'every 1 hour'), runs: 10, totalMs: 100.0, maxMs: 30.0, last: self::NOW - 600, failed: 1, lastFailed: self::NOW - 3000),
            self::task('h2', new TaskKey('default', 'gone', 'app:gamma', 'every 1 day'), runs: 2, totalMs: 400.0, maxMs: 300.0, last: self::NOW - 60, failed: 2, lastFailed: self::NOW - 60),
        ];
        $schedules = ['default' => [$alpha, $beta]];
        $ascending = [
            'next' => ['app:beta', 'app:alpha', 'app:gamma'],
            'task' => ['app:alpha', 'app:beta', 'app:gamma'],
            'last' => ['app:beta', 'app:alpha', 'app:gamma'],
            'runs' => ['app:beta', 'app:gamma', 'app:alpha'],
            'avg' => ['app:beta', 'app:alpha', 'app:gamma'],
            'max' => ['app:beta', 'app:alpha', 'app:gamma'],
            'failed' => ['app:beta', 'app:alpha', 'app:gamma'],
        ];

        static::assertSame(array_keys((new TasksSection(true, new DeclaredTasks()))->sorts()), array_keys($ascending));

        foreach ($ascending as $sort => $expected) {
            static::assertSame($expected, $this->labels($this->build($stored, $schedules, $sort, 'asc')), $sort . ' asc');
            static::assertSame(array_reverse($expected), $this->labels($this->build($stored, $schedules, $sort, 'desc')), $sort . ' desc');
        }
    }

    public function testOtherTasksComeLastMuted(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . self::NOW);
        $other = self::task('ho', null, runs: 9, totalMs: 90.0, maxMs: 20.0, last: self::NOW - 30);

        foreach ([['next', 'asc'], ['next', 'desc'], ['task', 'asc'], ['runs', 'desc']] as [$sort, $direction]) {
            $table = $this->table($this->build([
                self::task('h1', new TaskKey('default', 'a', 'app:a', 'every 1 day'), runs: 1, last: self::NOW - 60),
            ], ['default' => [$work]], $sort, $direction, $other));
            $last = $table->rows[2];

            static::assertCount(3, $table->rows);
            static::assertTrue($last->muted);
            static::assertSame('ho', $last->key);
            static::assertSame(['data-jm-task' => Aggregator::OTHER, 'data-jm-runs' => '9'], $last->attributes);
            static::assertSame(
                ['task' => 'Other tasks', 'schedule' => '—', 'trigger' => '—', 'next' => '—', 'last' => '30 s ago', 'runs' => '9', 'avg' => '10 ms', 'max' => '20 ms', 'failed' => '0'],
                self::texts($last),
            );
            static::assertSame('text', $last->cells['task']->kind);
        }
    }

    public function testTheTableShowsAHundredTasksAndCountsTheOthers(): void
    {
        $rows = [];
        for ($i = 1; $i <= 105; ++$i) {
            $rows[] = self::task('h' . $i, new TaskKey('default', 'id' . $i, sprintf('app:t%03d', $i), 'every 1 day'), runs: $i, last: self::NOW - 60);
        }

        $table = $this->table($this->build($rows, sort: 'runs', direction: 'desc'));

        static::assertCount(100, $table->rows);
        static::assertSame(5, $table->remaining);
        static::assertSame('app:t105', $table->rows[0]->attributes['data-jm-task']);
        static::assertSame('app:t006', $table->rows[99]->attributes['data-jm-task']);
    }

    public function testStoredTasksShowWhenEveryScheduleFails(): void
    {
        $declared = new DeclaredTasks(FixedSchedules::locator([
            'default' => static fn(): Schedule => throw new \RuntimeException('No schedule today'),
        ]));

        $view = (new TasksSection(true, $declared))->build(
            new FixedReader(new Report([self::task('h1', new TaskKey('default', 'a', 'app:work', 'every 1 hour'), runs: 1, last: self::NOW - 60)], keyCount: 1)),
            Period::OneHour,
            self::NOW,
            'next',
            'asc',
            new RaisedChecks([], [], Period::OneHour),
        );

        static::assertSame(['app:work'], $this->labels($view));
        static::assertSame('Tasks', $view->cards[1]->label);
        static::assertSame('0', $view->cards[1]->value);
    }

    public function testTheCards(): void
    {
        $hourly = RecurringMessage::every('1 hour', new RunCommandMessage('app:hourly'), '@' . (self::NOW - 600));
        $minutely = RecurringMessage::every('1 minute', new Ping(), '@' . (self::NOW - 30));

        $view = $this->build([
            self::task('h1', new TaskKey('default', $hourly->getId(), 'app:hourly', 'every 1 hour'), runs: 4, last: self::NOW - 600, failed: 1, lastFailed: self::NOW - 600),
        ], ['default' => [$hourly], 'reports' => [$minutely]], other: self::task('ho', null, runs: 3, failed: 2, last: self::NOW - 30), period: Period::SixHours, series: [2, 0, 1]);

        static::assertSame(['Runs', 'Tasks', 'Failed runs', 'Next run'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['7', '2', '3', 'in 30 s'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame(['Last 6 hours', 'Declared in a schedule', 'Ended with an exception', Ping::class], array_map(static fn(Card $card): string => $card->hint, $view->cards));
        static::assertSame([2, 0, 1], $view->cards[0]->series);
        static::assertSame([true, false, false, false], array_map(static fn(Card $card): bool => $card->wide, $view->cards));
        static::assertSame([null, null, 'bad', null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testCardsWithoutDeclaredTasks(): void
    {
        $view = $this->build([self::task('h1', new TaskKey('default', 'a', 'app:work', 'every 1 hour'), runs: 1, last: self::NOW - 60)]);

        static::assertSame(['1', '0', '0', '—'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame('No upcoming run', $view->cards[3]->hint);
        static::assertSame([null, null, null, null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testTheScreenIsEmptyOnlyWithoutDeclaredNorStoredTasks(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . self::NOW);

        static::assertTrue($this->build([])->isEmpty());
        static::assertFalse($this->build([], ['default' => [$work]])->isEmpty());
        static::assertFalse($this->build([], other: self::task('ho', null, runs: 1, last: self::NOW - 60))->isEmpty());
    }

    /**
     * @param list<ReportRow>                        $rows
     * @param array<string, list<RecurringMessage>> $schedules
     * @param list<int>                              $series
     */
    private function build(array $rows, array $schedules = [], string $sort = 'next', string $direction = 'asc', ?ReportRow $other = null, Period $period = Period::OneHour, array $series = []): ScreenView
    {
        $declared = new DeclaredTasks(FixedSchedules::locator(array_map(
            static fn(array $messages): \Closure => static fn(): Schedule => (new Schedule())->add(...$messages),
            $schedules,
        )));

        return (new TasksSection(true, $declared))->build(
            new FixedReader(static fn(ReportQuery $query): Report => new Report($rows, $other, \count($rows), $series)),
            $period,
            self::NOW,
            $sort,
            $direction,
            new RaisedChecks([], [], Period::OneHour),
        );
    }

    private function table(ScreenView $view): Table
    {
        return $view->tables[0];
    }

    /** @return list<string> */
    private function labels(ScreenView $view): array
    {
        return array_map(static fn(Row $row): string => $row->cells['task']->text, $this->table($view)->rows);
    }

    /** @return array<string, string> */
    private static function texts(Row $row): array
    {
        return array_map(static fn(Cell $cell): string => $cell->text, $row->cells);
    }

    private static function task(
        string $hash,
        ?TaskKey $key,
        int $runs = 1,
        float $totalMs = 10.0,
        float $maxMs = 10.0,
        int $last = 0,
        int $failed = 0,
        int $lastFailed = 0,
    ): ReportRow {
        $metrics = [
            Metric::TASK_DURATION => new MetricValue($runs, $totalMs, $maxMs),
            Metric::TASK_LAST_RUN => new MetricValue($runs, 0.0, (float) $last),
        ];
        if ($failed > 0) {
            $metrics[Metric::TASK_FAILED] = new MetricValue($failed, 0.0, (float) $lastFailed);
        }
        $storedKey = $key?->toString() ?? Aggregator::OTHER;

        return new ReportRow($hash, $storedKey, null, $metrics);
    }
}
