<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedCommandsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Section\CommandsSection;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandsSectionTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheSectionDeclaresItself(): void
    {
        $screen = self::section();

        static::assertSame('commands', $screen->id());
        static::assertSame('Commands', $screen->title());
        static::assertSame('Runs of bin/console, by cron, a deploy or by hand.', $screen->summary());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['last' => 'desc', 'command' => 'asc', 'runs' => 'desc', 'avg' => 'desc', 'max' => 'desc', 'problems' => 'desc', 'memory' => 'desc'],
            $screen->sorts(),
        );
        static::assertSame([
            'title' => 'No command ran in the last hour.',
            'text' => 'Run a console command: it shows up here as soon as it ends. Internal commands such as cache:clear and messenger:consume are not recorded.',
        ], $screen->emptyState(Period::OneHour));
        static::assertSame('No command ran in the last 7 days.', $screen->emptyState(Period::SevenDays)['title']);
    }

    public function testTheScreenFollowsItsAvailability(): void
    {
        static::assertFalse((new CommandsSection(false, self::checks()))->isAvailable());
        static::assertTrue((new CommandsSection(true, self::checks()))->isAvailable());
    }

    public function testTheScreenReadsTheCommandFamilyOnceWithoutASeries(): void
    {
        foreach (array_keys(self::section()->sorts()) as $sort) {
            $reader = new FixedReader();
            self::section()->build($reader, Period::SixHours, self::NOW, $sort, 'asc', self::raised());

            static::assertCount(1, $reader->queries, $sort);
            $query = $reader->queries[0];
            static::assertSame([Family::Command, Period::SixHours, self::NOW, null], [$query->family, $query->period, $query->now, $query->seriesMetric]);
            static::assertSame([], $reader->labelRequests);
            static::assertSame([], $reader->totalRequests);
        }
    }

    public function testTheCellsOfACommand(): void
    {
        $table = $this->table($this->build([self::command('h1', 'app:work', 4, 10000.0, 4000.0, failed: 1, memory: 2097152, lastRun: self::NOW - 90, sqlRuns: 2, sqlTime: 60.0, sqlCount: 3.0, httpRuns: 1, httpTime: 2000.0)]));
        $row = $table->rows[0];

        static::assertSame('commands', $table->id);
        static::assertSame('Commands', $table->title);
        static::assertSame('command', $table->noun);
        static::assertTrue($table->partsLegend);
        static::assertSame(
            [
                ['command', 'Command', false, 'command'],
                ['runs', 'Runs', true, 'runs'],
                ['avg', 'Avg', true, 'avg'],
                ['max', 'Max', true, 'max'],
                ['last', 'Last run', true, 'last'],
                ['split', 'Where the average goes', false, null],
                ['problems', 'Problems', false, 'problems'],
                ['memory', 'Memory', true, 'memory'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-command' => 'app:work', 'data-jm-runs' => '4'], $row->attributes);
        static::assertSame(
            ['command' => 'app:work', 'runs' => '4', 'avg' => '2.5 s', 'max' => '4.0 s', 'last' => '1 min ago', 'split' => 'HTTP out 500 ms · 0.8 queries', 'problems' => '', 'memory' => '2 MB'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('code', $row->cells['command']->kind);
        static::assertSame(['split', ['php' => 1985.0, 'sql' => 15.0, 'http' => 500.0]], [$row->cells['split']->kind, $row->cells['split']->parts]);
        static::assertSame([['1 failed', 'bad']], self::tags($row));
    }

    /**
     * @return iterable<string, array{ReportRow, string, array{php: float, sql: float, http: float}}>
     */
    public static function splits(): iterable
    {
        yield 'SQL and HTTP longer than the average' => [self::command('h', 'app:a', 2, 100.0, sqlRuns: 2, sqlTime: 80.0, sqlCount: 20.0, httpRuns: 2, httpTime: 60.0), 'SQL 40 ms · 10 queries', ['php' => 0.0, 'sql' => 40.0, 'http' => 30.0]];
        yield 'neither SQL nor an outgoing call' => [self::command('h', 'app:a', 2, 100.0), 'PHP only', ['php' => 50.0, 'sql' => 0.0, 'http' => 0.0]];
        yield 'an outgoing call only' => [self::command('h', 'app:a', 2, 100.0, httpRuns: 1, httpTime: 30.0), 'HTTP out 15 ms', ['php' => 35.0, 'sql' => 0.0, 'http' => 15.0]];
    }

    /**
     * @param array{php: float, sql: float, http: float} $parts
     */
    #[DataProvider('splits')]
    public function testWhereTheAverageRunGoes(ReportRow $command, string $text, array $parts): void
    {
        $split = $this->table($this->build([$command]))->rows[0]->cells['split'];

        static::assertSame(['split', $text, $parts], [$split->kind, $split->text, $split->parts]);
    }

    public function testACommandWithoutFailureHasNoTag(): void
    {
        $row = $this->table($this->build([self::command('h1', 'app:import')]))->rows[0];

        static::assertSame([], self::tags($row));
        static::assertSame('PHP only', $row->cells['split']->text);
    }

    public function testTheLastRunComesFirstByDefault(): void
    {
        $view = self::section()->build($this->reader([
            self::command('h1', 'app:old', lastRun: self::NOW - 3600),
            self::command('h2', 'app:recent', lastRun: self::NOW - 10),
            self::command('h3', 'app:middle', lastRun: self::NOW - 600),
        ]), Period::OneHour, self::NOW, (string) array_key_first(self::section()->sorts()), 'desc', self::raised());

        static::assertSame(['app:recent', 'app:middle', 'app:old'], $this->names($view));
    }

    public function testEverySortKey(): void
    {
        $rows = [
            self::command('ha', 'app:a', 10, 1000.0, 300.0, 0, 1024, self::NOW - 50, 10, 100.0, 10.0),
            self::command('hb', 'app:b', 2, 4000.0, 3000.0, 2, 8192, self::NOW - 400),
            self::command('hc', 'app:c', 5, 2500.0, 900.0, 1, 4096, self::NOW - 5, 5, 250.0, 5.0),
        ];
        $descending = [
            'last' => ['app:c', 'app:a', 'app:b'],
            'command' => ['app:c', 'app:b', 'app:a'],
            'runs' => ['app:a', 'app:c', 'app:b'],
            'avg' => ['app:b', 'app:c', 'app:a'],
            'max' => ['app:b', 'app:c', 'app:a'],
            'problems' => ['app:b', 'app:c', 'app:a'],
            'memory' => ['app:b', 'app:c', 'app:a'],
        ];

        static::assertSame(array_keys(self::section()->sorts()), array_keys($descending));

        foreach ($descending as $sort => $expected) {
            static::assertSame($expected, $this->names($this->build($rows, $sort, 'desc')), $sort . ' desc');
            static::assertSame(array_reverse($expected), $this->names($this->build($rows, $sort, 'asc')), $sort . ' asc');
        }
    }

    public function testARemovedSortFallsBackToTheDefault(): void
    {
        $rows = [
            self::command('ha', 'app:a', failed: 4, lastRun: self::NOW - 600, sqlRuns: 1, sqlTime: 900.0, sqlCount: 90.0),
            self::command('hb', 'app:b', lastRun: self::NOW - 5),
        ];

        foreach (['failed', 'sql', 'unknown'] as $sort) {
            static::assertSame(['app:b', 'app:a'], $this->names($this->build($rows, $sort, 'desc')), $sort);
        }
    }

    public function testOtherCommandsComeLastMutedWithTheirCells(): void
    {
        $other = self::command('ho', Aggregator::OTHER, 7, 700.0, 200.0, failed: 3, lastRun: self::NOW, sqlRuns: 7, sqlTime: 35.0, sqlCount: 14.0);

        foreach ([['last', 'desc'], ['last', 'asc'], ['command', 'asc'], ['runs', 'desc'], ['problems', 'desc']] as [$sort, $direction]) {
            $table = $this->table($this->build([self::command('h1', 'app:a'), self::command('h2', 'app:b', failed: 9)], $sort, $direction, $other));
            $last = $table->rows[2];

            static::assertCount(3, $table->rows);
            static::assertTrue($last->muted);
            static::assertSame('ho', $last->key);
            static::assertSame('Other commands', $last->cells['command']->text);
            static::assertSame('text', $last->cells['command']->kind);
            static::assertSame('7', $last->cells['runs']->text);
            static::assertSame('SQL 5 ms · 2.0 queries', $last->cells['split']->text);
            static::assertSame([['3 failed', 'bad']], self::tags($last));
            static::assertSame(['data-jm-command' => Aggregator::OTHER, 'data-jm-runs' => '7'], $last->attributes);
        }
    }

    public function testTheTableShowsAHundredCommandsAndCountsTheOthers(): void
    {
        $rows = [];
        for ($i = 1; $i <= 105; ++$i) {
            $rows[] = self::command('h' . $i, sprintf('app:%03d', $i), $i);
        }

        $table = $this->table($this->build($rows, 'runs', 'desc'));

        static::assertCount(100, $table->rows);
        static::assertSame(5, $table->remaining);
        static::assertSame('app:105', $table->rows[0]->attributes['data-jm-command']);
        static::assertSame('app:006', $table->rows[99]->attributes['data-jm-command']);
    }

    public function testNoCardIsRenderedInTheSection(): void
    {
        $view = $this->build([self::command('h1', 'app:a', 3, 3000.0, 2000.0, 1)], other: self::command('ho', Aggregator::OTHER));

        static::assertSame([], $view->cards);
        static::assertNull($view->lead);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, ?ReportRow, int, list<string|null>}>
     */
    public static function briefs(): iterable
    {
        yield 'runs of commands' => [[self::command('h1', 'app:a', 3), self::command('h2', 'app:b', 1)], null, 2, ['4', '4 command runs', 'runs of 2 commands', '', null]];
        yield 'one run of one command' => [[self::command('h1', 'app:a', 1)], null, 1, ['1', '1 command run', 'run of 1 command', '', null]];
        yield 'runs of one command' => [[self::command('h1', 'app:a', 5)], null, 1, ['5', '5 command runs', 'runs of 1 command', '', null]];
        yield 'one failed run' => [[self::command('h1', 'app:a', 3, failed: 1)], null, 1, ['3', '3 command runs', 'runs of 1 command, 1 failed', '1 command run failed.', 'bad']];
        yield 'the other commands counted' => [
            [self::command('h1', 'app:a', 1000, failed: 2)],
            self::command('ho', Aggregator::OTHER, 234, failed: 1),
            1,
            ['1,234', '1,234 command runs', 'runs of 1 command, 3 failed', '3 command runs failed.', 'bad'],
        ];
    }

    /**
     * @param list<ReportRow>   $rows
     * @param list<string|null> $expected
     */
    #[DataProvider('briefs')]
    public function testTheBrief(array $rows, ?ReportRow $other, int $keyCount, array $expected): void
    {
        $view = self::section()->build(
            new FixedReader(static fn(ReportQuery $query): Report => new Report($rows, $other, $keyCount)),
            Period::OneHour,
            self::NOW,
            'last',
            'desc',
            self::raised(),
        );

        static::assertNotNull($view->brief);
        static::assertSame($expected, [$view->brief->count, $view->brief->clause, $view->brief->figures, $view->brief->standout, $view->brief->tone]);
    }

    public function testTheCheckLineIsReadInTheReportOfTheSection(): void
    {
        $reader = $this->reader([self::command('h1', 'app:import', 4, failed: 2), self::command('h2', 'app:sync', 2)]);
        $check = new FailedCommandsCheck();
        $raised = new RaisedChecks([$check->id() => $check], [Metric::COMMAND_FAILED => new MetricValue(2, 2.0, 1.0)], Period::OneHour);

        $view = self::section()->build($reader, Period::OneHour, self::NOW, 'last', 'desc', $raised);

        static::assertNotNull($view->checks);
        static::assertSame('no failed command run', $view->checks->emptyText);
        static::assertSame(
            [['commands.failed', 'bad', '2 command runs failed', 'app:import ended with a non-zero exit code 2 times.', '', null]],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->mark->tone, $line->title, $line->sentence, $line->where, $line->path], $view->checks->lines),
        );
        static::assertCount(1, $reader->queries);
        static::assertSame([], $reader->totalRequests);
    }

    public function testWithoutRaisedCheckThereIsNoLine(): void
    {
        $view = $this->build([self::command('h1', 'app:import', failed: 1)]);

        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
        static::assertSame('no failed command run', $view->checks->emptyText);
    }

    public function testTheGuide(): void
    {
        $guide = $this->build([self::command('h1', 'app:a')])->guide;

        static::assertNotNull($guide);
        static::assertSame(
            [['Commands.', 'Internal commands such as cache:clear and messenger:consume are not recorded.', []]],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text, $item->links], $guide->items),
        );
    }

    public function testAnEmptyPeriodShowsTheEmptyState(): void
    {
        $view = $this->build([]);

        static::assertTrue($view->isEmpty());
        static::assertNull($view->brief);
        static::assertNull($view->checks);
        static::assertNull($view->guide);
        static::assertFalse($this->build([], other: self::command('ho', Aggregator::OTHER))->isEmpty());
    }

    private static function section(): CommandsSection
    {
        return new CommandsSection(true, self::checks());
    }

    private static function checks(): ApplicationChecks
    {
        return new ApplicationChecks([new FailedCommandsCheck()]);
    }

    private static function raised(): RaisedChecks
    {
        return new RaisedChecks([], [], Period::OneHour);
    }

    /**
     * @param list<ReportRow> $rows
     */
    private function build(array $rows, string $sort = 'last', string $direction = 'desc', ?ReportRow $other = null): ScreenView
    {
        return self::section()->build($this->reader($rows, $other), Period::OneHour, self::NOW, $sort, $direction, self::raised());
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
    private function names(ScreenView $view): array
    {
        return array_map(static fn(Row $row): string => $row->cells['command']->text, $this->table($view)->rows);
    }

    /** @return list<array{string, string}> */
    private static function tags(Row $row): array
    {
        return array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $row->cells['problems']->tags);
    }

    private static function command(
        string $hash,
        string $name,
        int $runs = 1,
        float $totalMs = 100.0,
        float $maxMs = 100.0,
        int $failed = 0,
        int $memory = 1024,
        int $lastRun = self::NOW,
        int $sqlRuns = 0,
        float $sqlTime = 0.0,
        float $sqlCount = 0.0,
        int $httpRuns = 0,
        float $httpTime = 0.0,
    ): ReportRow {
        $metrics = [
            Metric::COMMAND_DURATION => new MetricValue($runs, $totalMs, $maxMs),
            Metric::COMMAND_LAST_RUN => new MetricValue($runs, 0.0, (float) $lastRun),
            Metric::COMMAND_MEMORY => new MetricValue($runs, (float) ($memory * $runs), (float) $memory),
        ];
        if ($failed > 0) {
            $metrics[Metric::COMMAND_FAILED] = new MetricValue($failed, (float) $failed, 1.0);
        }
        if ($sqlRuns > 0) {
            $metrics[Metric::COMMAND_SQL_TIME] = new MetricValue($sqlRuns, $sqlTime, $sqlTime);
            $metrics[Metric::COMMAND_SQL_COUNT] = new MetricValue($sqlRuns, $sqlCount, $sqlCount);
        }
        if ($httpRuns > 0) {
            $metrics[Metric::COMMAND_HTTP_TIME] = new MetricValue($httpRuns, $httpTime, $httpTime);
        }

        return new ReportRow($hash, $name, $name, $metrics);
    }
}
