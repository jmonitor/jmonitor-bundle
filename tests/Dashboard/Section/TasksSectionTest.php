<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedTasksCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\Section\TasksSection;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedSchedules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

final class TasksSectionTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheSectionDeclaresItself(): void
    {
        $screen = self::section(new DeclaredTasks());

        static::assertSame('tasks', $screen->id());
        static::assertSame('Scheduled tasks', $screen->title());
        static::assertSame('What Symfony Scheduler starts by the clock.', $screen->summary());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['next' => 'asc', 'task' => 'asc', 'last' => 'desc', 'runs' => 'desc', 'avg' => 'desc', 'problems' => 'desc'],
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
        static::assertFalse((new TasksSection(false, new DeclaredTasks(), self::checks()))->isAvailable());
        static::assertTrue((new TasksSection(true, new DeclaredTasks(), self::checks()))->isAvailable());
    }

    public function testTheScreenReadsTheTaskFamilyOnceWithoutASeries(): void
    {
        $screen = self::section(new DeclaredTasks());

        foreach (array_keys($screen->sorts()) as $sort) {
            $reader = new FixedReader();
            $screen->build($reader, Period::SixHours, self::NOW, $sort, 'asc', self::raised());

            static::assertCount(1, $reader->queries, $sort);
            $query = $reader->queries[0];
            static::assertSame([Family::Task, Period::SixHours, self::NOW, null], [$query->family, $query->period, $query->now, $query->seriesMetric]);
            static::assertSame([], $reader->labelRequests);
            static::assertSame([], $reader->totalRequests);
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
        static::assertFalse($table->partsLegend);
        static::assertSame(
            [
                ['task', 'Task', false, 'task'],
                ['schedule', 'Schedule', false, null],
                ['next', 'Next run', true, 'next'],
                ['last', 'Last run', true, 'last'],
                ['runs', 'Runs', true, 'runs'],
                ['avg', 'Avg', true, 'avg'],
                ['problems', 'Problems', false, 'problems'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertCount(1, $table->rows);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-task' => 'app:work', 'data-jm-runs' => '0'], $row->attributes);
        static::assertSame(
            ['task' => 'app:work', 'schedule' => 'every 1 hour', 'next' => 'in 50 min', 'last' => '—', 'runs' => '0', 'avg' => '—', 'problems' => ''],
            self::texts($row),
        );
        static::assertSame('code', $row->cells['task']->kind);
        static::assertSame(['zero', 'zero', 'zero'], array_map(static fn(string $id): ?string => $row->cells[$id]->tone, ['last', 'runs', 'avg']));
        static::assertNull($row->cells['next']->tone);
        static::assertNull($row->cells['schedule']->tone);
        static::assertSame([], self::tags($row));
    }

    public function testATaskThatRanButIsNoLongerDeclared(): void
    {
        $row = $this->table($this->build([
            self::task('h1', new TaskKey('default', 'gone', 'app:old', 'every 1 day'), runs: 4, totalMs: 200.0, maxMs: 90.0, last: self::NOW - 600),
        ]))->rows[0];

        static::assertSame(['data-jm-task' => 'app:old', 'data-jm-runs' => '4'], $row->attributes);
        static::assertSame(
            ['task' => 'app:old', 'schedule' => 'every 1 day', 'next' => '—', 'last' => '10 min ago', 'runs' => '4', 'avg' => '50 ms', 'problems' => ''],
            self::texts($row),
        );
        static::assertSame('zero', $row->cells['next']->tone);
        static::assertNull($row->cells['last']->tone);
        static::assertNull($row->cells['runs']->tone);
    }

    public function testAScheduleThatIsNotKnownIsADash(): void
    {
        $row = $this->table($this->build([self::task('h1', null, runs: 2, last: self::NOW - 60, storedKey: 'app:unparsed')]))->rows[0];

        static::assertSame(['app:unparsed', '—', 'zero'], [$row->cells['task']->text, $row->cells['schedule']->text, $row->cells['schedule']->tone]);
    }

    public function testADeclaredTaskAndItsRunsShareOneRowEvenWhenTheirLabelsDiffer(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . (self::NOW - 600));
        $stored = self::task('h1', new TaskKey('default', $work->getId(), 'app:work --before-rename', 'every 1 hour'), runs: 2, totalMs: 30.0, maxMs: 20.0, last: self::NOW - 120);

        $rows = $this->table($this->build([$stored], ['default' => [$work]]))->rows;

        static::assertCount(1, $rows);
        static::assertSame(['data-jm-task' => 'app:work', 'data-jm-runs' => '2'], $rows[0]->attributes);
        static::assertSame(
            ['task' => 'app:work', 'schedule' => 'every 1 hour', 'next' => 'in 50 min', 'last' => '2 min ago', 'runs' => '2', 'avg' => '15 ms', 'problems' => ''],
            self::texts($rows[0]),
        );
    }

    public function testTheSameIdInAnotherScheduleIsAnotherTask(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . self::NOW);

        $rows = $this->table($this->build([
            self::task('h1', new TaskKey('reports', $work->getId(), 'app:work', 'every 1 hour'), runs: 1, last: self::NOW - 60),
        ], ['default' => [$work]]))->rows;

        static::assertSame([['app:work', '0'], ['app:work', '1']], array_map(static fn(Row $row): array => [$row->cells['task']->text, $row->attributes['data-jm-runs']], $rows));
        static::assertNotSame($rows[0]->key, $rows[1]->key);
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
        static::assertSame([['1 failed', 'bad']], self::tags($rows[0]));
        static::assertSame([['2 failed', 'bad']], self::tags($rows[1]));
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
            'problems' => ['app:beta', 'app:alpha', 'app:gamma'],
        ];

        static::assertSame(array_keys(self::section(new DeclaredTasks())->sorts()), array_keys($ascending));

        foreach ($ascending as $sort => $expected) {
            static::assertSame($expected, $this->labels($this->build($stored, $schedules, $sort, 'asc')), $sort . ' asc');
            static::assertSame(array_reverse($expected), $this->labels($this->build($stored, $schedules, $sort, 'desc')), $sort . ' desc');
        }
    }

    public function testARemovedSortFallsBackToTheDefault(): void
    {
        $alpha = RecurringMessage::every('1 hour', new RunCommandMessage('app:alpha'), '@' . (self::NOW - 600));
        $beta = RecurringMessage::every('1 minute', new RunCommandMessage('app:beta'), '@' . (self::NOW - 30));
        $stored = [self::task('h1', new TaskKey('default', $alpha->getId(), 'app:alpha', 'every 1 hour'), runs: 3, maxMs: 900.0, failed: 3, lastFailed: self::NOW - 60)];

        foreach (['max', 'failed', 'unknown'] as $sort) {
            static::assertSame(['app:beta', 'app:alpha'], $this->labels($this->build($stored, ['default' => [$alpha, $beta]], $sort, 'asc')), $sort);
        }
    }

    public function testOtherTasksComeLastMutedWithTheirCells(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . self::NOW);
        $other = self::task('ho', null, runs: 9, totalMs: 90.0, maxMs: 20.0, last: self::NOW - 30, failed: 2, lastFailed: self::NOW - 30);

        foreach ([['next', 'asc'], ['next', 'desc'], ['task', 'asc'], ['runs', 'desc'], ['problems', 'desc']] as [$sort, $direction]) {
            $table = $this->table($this->build([
                self::task('h1', new TaskKey('default', 'a', 'app:a', 'every 1 day'), runs: 1, last: self::NOW - 60),
            ], ['default' => [$work]], $sort, $direction, $other));
            $last = $table->rows[2];

            static::assertCount(3, $table->rows);
            static::assertTrue($last->muted);
            static::assertSame('ho', $last->key);
            static::assertSame(['data-jm-task' => Aggregator::OTHER, 'data-jm-runs' => '9'], $last->attributes);
            static::assertSame(
                ['task' => 'Other tasks', 'schedule' => '—', 'next' => '—', 'last' => '30 s ago · failed', 'runs' => '9', 'avg' => '10 ms', 'problems' => ''],
                self::texts($last),
            );
            static::assertSame('text', $last->cells['task']->kind);
            static::assertSame([['2 failed', 'bad']], self::tags($last));
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

        $view = self::section($declared)->build(
            new FixedReader(new Report([self::task('h1', new TaskKey('default', 'a', 'app:work', 'every 1 hour'), runs: 1, last: self::NOW - 60)], keyCount: 1)),
            Period::OneHour,
            self::NOW,
            'next',
            'asc',
            self::raised(),
        );

        static::assertSame(['app:work'], $this->labels($view));
        static::assertNotNull($view->brief);
        static::assertSame('run', $view->brief->figures);
    }

    public function testNoCardIsRenderedInTheSection(): void
    {
        $hourly = RecurringMessage::every('1 hour', new RunCommandMessage('app:hourly'), '@' . (self::NOW - 600));

        $view = $this->build([
            self::task('h1', new TaskKey('default', $hourly->getId(), 'app:hourly', 'every 1 hour'), runs: 4, last: self::NOW - 600, failed: 1, lastFailed: self::NOW - 600),
        ], ['default' => [$hourly]]);

        static::assertSame([], $view->cards);
        static::assertNull($view->lead);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, array<string, list<RecurringMessage>>, ?ReportRow, list<string|null>}>
     */
    public static function briefs(): iterable
    {
        $declared = static fn(int $count): array => ['default' => array_map(
            static fn(int $i): RecurringMessage => RecurringMessage::every('1 hour', new RunCommandMessage('app:t' . $i), '@' . self::NOW),
            range(1, $count),
        )];
        $ran = static fn(string $hash, int $runs, int $failed = 0): ReportRow => self::task($hash, new TaskKey('default', $hash, 'app:' . $hash, 'every 1 day'), runs: $runs, last: self::NOW - 60, failed: $failed, lastFailed: self::NOW - 60);

        yield 'runs of declared tasks' => [[$ran('a', 7), $ran('b', 5)], $declared(6), null, ['12', '12 scheduled runs', 'runs of 6 declared tasks', '', null]];
        yield 'one run of one declared task' => [[$ran('a', 1)], $declared(1), null, ['1', '1 scheduled run', 'run of 1 declared task', '', null]];
        yield 'runs without a declared task' => [[$ran('a', 3)], [], null, ['3', '3 scheduled runs', 'runs', '', null]];
        yield 'one run without a declared task' => [[$ran('a', 1)], [], null, ['1', '1 scheduled run', 'run', '', null]];
        yield 'one failed run' => [[$ran('a', 4, 1)], $declared(2), null, ['4', '4 scheduled runs', 'runs of 2 declared tasks, 1 failed', '1 scheduled run failed.', 'bad']];
        yield 'the other tasks counted' => [
            [$ran('a', 1000, 2)],
            [],
            self::task('ho', null, runs: 234, last: self::NOW - 30, failed: 1, lastFailed: self::NOW - 30),
            ['1,234', '1,234 scheduled runs', 'runs, 3 failed', '3 scheduled runs failed.', 'bad'],
        ];
    }

    /**
     * @param list<ReportRow>                        $rows
     * @param array<string, list<RecurringMessage>> $schedules
     * @param list<string|null>                     $expected
     */
    #[DataProvider('briefs')]
    public function testTheBrief(array $rows, array $schedules, ?ReportRow $other, array $expected): void
    {
        $brief = $this->build($rows, $schedules, other: $other)->brief;

        static::assertNotNull($brief);
        static::assertSame($expected, [$brief->count, $brief->clause, $brief->figures, $brief->standout, $brief->tone]);
    }

    public function testATaskWithoutRunInThePeriodBringsNoBrief(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . self::NOW);

        $view = $this->build([], ['default' => [$work]]);

        static::assertFalse($view->isEmpty());
        static::assertNull($view->brief);
        static::assertSame(['app:work'], $this->labels($view));
    }

    public function testTheCheckLineIsReadInTheReportOfTheSection(): void
    {
        $reader = $this->reader([
            self::task('h1', new TaskKey('default', 'a', 'app:import', 'every 1 hour'), runs: 4, last: self::NOW - 60, failed: 3, lastFailed: self::NOW - 60),
            self::task('h2', new TaskKey('default', 'b', 'app:purge', 'every 1 day'), runs: 1, last: self::NOW - 60, failed: 1, lastFailed: self::NOW - 60),
        ]);
        $check = new FailedTasksCheck();
        $raised = new RaisedChecks([$check->id() => $check], [Metric::TASK_FAILED => new MetricValue(4, 0.0, (float) (self::NOW - 60))], Period::OneHour);

        $view = self::section(new DeclaredTasks())->build($reader, Period::OneHour, self::NOW, 'next', 'asc', $raised);

        static::assertNotNull($view->checks);
        static::assertSame('no failed scheduled run', $view->checks->emptyText);
        static::assertSame(
            [['tasks.failed', 'bad', '4 scheduled runs failed', 'app:import failed 3 times. 1 other task failed too.', '', null]],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->mark->tone, $line->title, $line->sentence, $line->where, $line->path], $view->checks->lines),
        );
        static::assertCount(1, $reader->queries);
        static::assertSame([], $reader->totalRequests);
    }

    public function testWithoutRaisedCheckThereIsNoLine(): void
    {
        $view = $this->build([self::task('h1', new TaskKey('default', 'a', 'app:import', 'every 1 hour'), runs: 1, last: self::NOW - 60, failed: 1)]);

        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
        static::assertSame('no failed scheduled run', $view->checks->emptyText);
    }

    public function testTheGuide(): void
    {
        $guide = $this->build([self::task('h1', new TaskKey('default', 'a', 'app:import', 'every 1 hour'), runs: 1, last: self::NOW - 60)])->guide;

        static::assertNotNull($guide);
        static::assertSame(
            [['A task with 0 run.', 'Normal for a daily task seen over one hour. Switch the period to 24h or 7d: if it still shows no run, no worker consumes the schedule.', []]],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text, $item->links], $guide->items),
        );
    }

    public function testTheScreenIsEmptyOnlyWithoutDeclaredNorStoredTasks(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . self::NOW);

        $empty = $this->build([]);
        static::assertTrue($empty->isEmpty());
        static::assertNull($empty->brief);
        static::assertNull($empty->checks);
        static::assertNull($empty->guide);
        static::assertFalse($this->build([], ['default' => [$work]])->isEmpty());
        static::assertFalse($this->build([], other: self::task('ho', null, runs: 1, last: self::NOW - 60))->isEmpty());
    }

    private static function section(DeclaredTasks $declared): TasksSection
    {
        return new TasksSection(true, $declared, self::checks());
    }

    private static function checks(): ApplicationChecks
    {
        return new ApplicationChecks([new FailedTasksCheck()]);
    }

    private static function raised(): RaisedChecks
    {
        return new RaisedChecks([], [], Period::OneHour);
    }

    /**
     * @param list<ReportRow>                        $rows
     * @param array<string, list<RecurringMessage>> $schedules
     */
    private function build(array $rows, array $schedules = [], string $sort = 'next', string $direction = 'asc', ?ReportRow $other = null): ScreenView
    {
        $declared = new DeclaredTasks(FixedSchedules::locator(array_map(
            static fn(array $messages): \Closure => static fn(): Schedule => (new Schedule())->add(...$messages),
            $schedules,
        )));

        return self::section($declared)->build($this->reader($rows, $other), Period::OneHour, self::NOW, $sort, $direction, self::raised());
    }

    /**
     * @param list<ReportRow> $rows
     */
    private function reader(array $rows, ?ReportRow $other = null): FixedReader
    {
        return new FixedReader(static fn(ReportQuery $query): Report => new Report($rows, $other, \count($rows)));
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

    /** @return list<array{string, string}> */
    private static function tags(Row $row): array
    {
        return array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $row->cells['problems']->tags);
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
        ?string $storedKey = null,
    ): ReportRow {
        $metrics = [
            Metric::TASK_DURATION => new MetricValue($runs, $totalMs, $maxMs),
            Metric::TASK_LAST_RUN => new MetricValue($runs, 0.0, (float) $last),
        ];
        if ($failed > 0) {
            $metrics[Metric::TASK_FAILED] = new MetricValue($failed, 0.0, (float) $lastFailed);
        }

        return new ReportRow($hash, $storedKey ?? $key?->toString() ?? Aggregator::OTHER, null, $metrics);
    }
}
