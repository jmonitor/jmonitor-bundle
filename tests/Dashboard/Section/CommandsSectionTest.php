<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Section\CommandsSection;
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
use PHPUnit\Framework\TestCase;

final class CommandsSectionTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheSectionDeclaresItself(): void
    {
        $screen = new CommandsSection(true);

        static::assertSame('commands', $screen->id());
        static::assertSame('Commands', $screen->title());
        static::assertSame('Runs of bin/console, by cron, a deploy or by hand.', $screen->summary());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['last' => 'desc', 'command' => 'asc', 'runs' => 'desc', 'avg' => 'desc', 'max' => 'desc', 'failed' => 'desc', 'memory' => 'desc', 'sql' => 'desc'],
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
        static::assertFalse((new CommandsSection(false))->isAvailable());
        static::assertTrue((new CommandsSection(true))->isAvailable());
    }

    public function testTheScreenReadsTheCommandFamilyOnceWithItsSeries(): void
    {
        foreach (array_keys((new CommandsSection(true))->sorts()) as $sort) {
            $reader = new FixedReader();
            (new CommandsSection(true))->build($reader, Period::SixHours, self::NOW, $sort, 'asc');

            static::assertCount(1, $reader->queries, $sort);
            $query = $reader->queries[0];
            static::assertSame([Family::Command, Period::SixHours, self::NOW, Metric::COMMAND_DURATION], [$query->family, $query->period, $query->now, $query->seriesMetric]);
            static::assertSame([], $reader->labelRequests);
        }
    }

    public function testTheCellsOfACommand(): void
    {
        $table = $this->table($this->build([self::command('h1', 'app:work', 4, 10000.0, 4000.0, failed: 1, memory: 2097152, lastRun: self::NOW - 90, sqlRuns: 2, sqlTime: 60.0, sqlCount: 3.0)]));
        $row = $table->rows[0];

        static::assertSame('commands', $table->id);
        static::assertSame('Commands', $table->title);
        static::assertSame('command', $table->noun);
        static::assertSame(
            [
                ['command', 'Command', false, 'command'],
                ['runs', 'Runs', true, 'runs'],
                ['avg', 'Avg', true, 'avg'],
                ['max', 'Max', true, 'max'],
                ['failed', 'Failed', true, 'failed'],
                ['memory', 'Peak memory', true, 'memory'],
                ['last', 'Last run', true, 'last'],
                ['sql', 'SQL', true, 'sql'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-command' => 'app:work', 'data-jm-runs' => '4'], $row->attributes);
        static::assertSame(
            ['command' => 'app:work', 'runs' => '4', 'avg' => '2.5 s', 'max' => '4.0 s', 'failed' => '1', 'memory' => '2 MB', 'last' => '1 min ago', 'sql' => '15 ms · 0.8 q'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('code', $row->cells['command']->kind);
        static::assertSame('bad', $row->cells['failed']->tone);
        static::assertNull($row->cells['sql']->tone);
    }

    public function testACommandWithoutFailureOrSqlStaysNeutral(): void
    {
        $row = $this->table($this->build([self::command('h1', 'app:import')]))->rows[0];

        static::assertSame(['0', 'zero'], [$row->cells['failed']->text, $row->cells['failed']->tone]);
        static::assertSame(['—', 'zero'], [$row->cells['sql']->text, $row->cells['sql']->tone]);
    }

    public function testTheLastRunComesFirstByDefault(): void
    {
        $view = (new CommandsSection(true))->build($this->reader([
            self::command('h1', 'app:old', lastRun: self::NOW - 3600),
            self::command('h2', 'app:recent', lastRun: self::NOW - 10),
            self::command('h3', 'app:middle', lastRun: self::NOW - 600),
        ]), Period::OneHour, self::NOW, array_key_first((new CommandsSection(true))->sorts()), 'desc');

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
            'failed' => ['app:b', 'app:c', 'app:a'],
            'memory' => ['app:b', 'app:c', 'app:a'],
            'sql' => ['app:c', 'app:a', 'app:b'],
        ];

        static::assertSame(array_keys((new CommandsSection(true))->sorts()), array_keys($descending));

        foreach ($descending as $sort => $expected) {
            static::assertSame($expected, $this->names($this->build($rows, $sort, 'desc')), $sort . ' desc');
            static::assertSame(array_reverse($expected), $this->names($this->build($rows, $sort, 'asc')), $sort . ' asc');
        }
    }

    public function testOtherCommandsComeLastMuted(): void
    {
        $other = self::command('ho', Aggregator::OTHER, 7, 700.0, 200.0, lastRun: self::NOW);

        foreach ([['last', 'desc'], ['last', 'asc'], ['command', 'asc'], ['runs', 'desc']] as [$sort, $direction]) {
            $table = $this->table($this->build([self::command('h1', 'app:a'), self::command('h2', 'app:b')], $sort, $direction, $other));
            $last = $table->rows[2];

            static::assertCount(3, $table->rows);
            static::assertTrue($last->muted);
            static::assertSame('ho', $last->key);
            static::assertSame('Other commands', $last->cells['command']->text);
            static::assertSame('text', $last->cells['command']->kind);
            static::assertSame('7', $last->cells['runs']->text);
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

    public function testTheCards(): void
    {
        $view = $this->build([
            self::command('h1', 'app:a', 3, 3000.0, 2000.0, 1),
            self::command('h2', 'app:b', 1, 500.0, 500.0),
        ], other: self::command('ho', Aggregator::OTHER, 1, 500.0, 500.0, 1), period: Period::OneDay, series: [1, 0, 3]);

        static::assertSame(['Runs', 'Failed runs', 'Avg duration', 'Commands'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['5', '2', '800 ms', '2'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame('Last 24 hours', $view->cards[0]->hint);
        static::assertSame([1, 0, 3], $view->cards[0]->series);
        static::assertTrue($view->cards[0]->wide);
        static::assertSame([null, 'bad', null, null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testCardsWithoutFailureStayNeutral(): void
    {
        $view = $this->build([self::command('h1', 'app:a')]);

        static::assertSame('0', $view->cards[1]->value);
        static::assertNull($view->cards[1]->tone);
    }

    public function testAnEmptyPeriodShowsTheEmptyState(): void
    {
        static::assertTrue($this->build([])->isEmpty());
        static::assertFalse($this->build([], other: self::command('ho', Aggregator::OTHER))->isEmpty());
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<int>       $series
     */
    private function build(array $rows, string $sort = 'last', string $direction = 'desc', ?ReportRow $other = null, Period $period = Period::OneHour, array $series = []): ScreenView
    {
        return (new CommandsSection(true))->build($this->reader($rows, $other, $series), $period, self::NOW, $sort, $direction);
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<int>       $series
     */
    private function reader(array $rows, ?ReportRow $other = null, array $series = []): FixedReader
    {
        return new FixedReader(static fn(ReportQuery $query): Report => new Report($rows, $other, \count($rows), $series));
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

        return new ReportRow($hash, $name, $name, $metrics);
    }
}
