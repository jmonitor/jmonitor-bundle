<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\TestCase;

final class QueriesScreenTest extends TestCase
{
    private const NOW = 1800000000;
    private const SQL = 'SELECT * FROM demo_items WHERE id = ?';
    private const QUERY_ID = '0123456789abcdef';

    public function testTheScreenDeclaresItself(): void
    {
        $screen = $this->screen();

        static::assertSame('queries', $screen->id());
        static::assertSame('Queries', $screen->label());
        static::assertSame('SQL queries run through Doctrine DBAL', $screen->heading());
        static::assertTrue($screen->isAvailable());
        static::assertSame(['total' => 'desc', 'count' => 'desc', 'avg' => 'desc', 'max' => 'desc', 'slow' => 'desc'], $screen->sorts());
        static::assertSame(
            ['title' => 'No queries recorded yet', 'text' => 'SQL queries run through Doctrine DBAL show up here within a few seconds.'],
            $screen->emptyState(),
        );
    }

    public function testTheScreenIsUnavailableWithoutSqlRecording(): void
    {
        static::assertFalse((new QueriesScreen(false, 100, 10))->isAvailable());
    }

    public function testEverySortReadsTheQueryFamilyInTheDatabase(): void
    {
        $sorts = [
            'total' => [Metric::QUERY_DURATION, 'sum'],
            'count' => [Metric::QUERY_DURATION, 'count'],
            'avg' => [Metric::QUERY_DURATION, 'avg'],
            'max' => [Metric::QUERY_DURATION, 'max'],
            'slow' => [Metric::QUERY_SLOW, 'count'],
        ];

        static::assertSame(array_keys($this->screen()->sorts()), array_keys($sorts));

        foreach ($sorts as $sort => [$metric, $field]) {
            foreach (['desc' => true, 'asc' => false] as $direction => $descending) {
                $reader = new FixedReader();
                $this->screen()->build($reader, Period::SixHours, self::NOW, $sort, $direction);
                $query = $reader->queries[0];

                static::assertSame(Family::Query, $query->family, $sort);
                static::assertSame(Period::SixHours, $query->period);
                static::assertSame(self::NOW, $query->now);
                static::assertSame($metric, $query->sortMetric, $sort);
                static::assertSame($field, $query->sortField, $sort);
                static::assertSame($descending, $query->descending, $sort . ' ' . $direction);
                static::assertSame(100, $query->limit);
                static::assertSame(Metric::QUERY_DURATION, $query->seriesMetric);

                static::assertCount(2, $reader->queries, $sort . ' ' . $direction);
                $nPlusOne = $reader->queries[1];
                static::assertSame(Family::NPlusOne, $nPlusOne->family);
                static::assertSame(Metric::NPLUS1_DURATION, $nPlusOne->sortMetric);
                static::assertSame('sum', $nPlusOne->sortField);
                static::assertTrue($nPlusOne->descending);
                static::assertSame(100, $nPlusOne->limit);
                static::assertSame(Period::SixHours, $nPlusOne->period);
            }
        }
    }

    public function testTheCellsOfAQuery(): void
    {
        $table = $this->table($this->build([$this->query('h1', self::SQL, 12, 60.0, 30.0, 2)]));
        $row = $table->rows[0];

        static::assertSame('queries', $table->id);
        static::assertSame('Queries', $table->title);
        static::assertSame('query', $table->noun);
        static::assertSame(
            [['query', 'Query', false, null], ['executions', 'Executions', true, 'count'], ['total', 'Total time', true, 'total'], ['avg', 'Avg', true, 'avg'], ['max', 'Max', true, 'max'], ['slow', 'Slow', true, 'slow']],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertSame(['data-jm-query' => 'h1', 'data-jm-executions' => '12'], $row->attributes);
        static::assertFalse($row->muted);
        static::assertSame(
            ['query' => self::SQL, 'executions' => '12', 'total' => '60 ms', 'avg' => '5 ms', 'max' => '30 ms', 'slow' => '2'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('sql', $row->cells['query']->kind);
        static::assertSame('warn', $row->cells['max']->tone);
        static::assertSame('warn', $row->cells['slow']->tone);
        static::assertNull($row->cells['total']->tone);
    }

    public function testAQueryWithoutSlowExecutionIsNotHighlighted(): void
    {
        $row = $this->table($this->build([$this->query('h1', self::SQL, 3, 6.0, 3.0)]))->rows[0];

        static::assertSame('0', $row->cells['slow']->text);
        static::assertSame('zero', $row->cells['slow']->tone);
        static::assertNull($row->cells['max']->tone);
    }

    public function testAQueryWhoseTextIsNotStoredYetSaysSo(): void
    {
        $row = $this->table($this->build([$this->query('h1', null)]))->rows[0];

        static::assertSame('(query text unavailable)', $row->cells['query']->text);
        static::assertSame('text', $row->cells['query']->kind);
        static::assertSame('h1', $row->attributes['data-jm-query']);
    }

    public function testOtherQueriesComeLastAndTheRestIsCounted(): void
    {
        $other = new ReportRow('ho', Aggregator::OTHER, null, [Metric::QUERY_DURATION => new MetricValue(50, 500.0, 40.0)]);
        $table = $this->table($this->build([$this->query('h1', 'SELECT 1'), $this->query('h2', 'SELECT 2')], other: $other, keyCount: 130));

        static::assertCount(3, $table->rows);
        static::assertSame(['h1', 'h2', 'ho'], array_map(static fn($row): string => $row->key, $table->rows));
        static::assertTrue($table->rows[2]->muted);
        static::assertSame('Other queries', $table->rows[2]->cells['query']->text);
        static::assertSame('text', $table->rows[2]->cells['query']->kind);
        static::assertSame('50', $table->rows[2]->cells['executions']->text);
        static::assertSame(128, $table->remaining);
    }

    public function testTheRemainingRowsFollowTheKeysOfTheSortMetric(): void
    {
        $table = $this->table($this->build([$this->query('h1', 'SELECT 1', slow: 1)], 'slow', keyCount: 1));

        static::assertSame(0, $table->remaining);
    }

    public function testTheCards(): void
    {
        $other = new ReportRow('ho', Aggregator::OTHER, null, [Metric::QUERY_DURATION => new MetricValue(10, 40.0, 9.0)]);
        $view = $this->build([
            $this->query('h1', 'SELECT 1', 12, 60.0, 30.0, 2),
            $this->query('h2', 'SELECT 2', 3, 1500.0, 900.0, 1),
        ], other: $other, series: [10, 15], nPlusOne: [$this->nPlusOne('n1', 'GET app_home', self::QUERY_ID)], period: Period::SixHours, totals: self::totals(25, 1600.0, 3));

        static::assertSame(['Queries', 'SQL time', 'Slow queries', 'Repeated queries'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['25', '1.6 s', '3', '1'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame('Last 6 hours', $view->cards[0]->hint);
        static::assertSame('100 ms or more', $view->cards[2]->hint);
        static::assertSame('Same query 10 times or more in one request', $view->cards[3]->hint);
        static::assertSame([10, 15], $view->cards[0]->series);
        static::assertTrue($view->cards[0]->wide);
        static::assertSame([null, null, 'warn', 'warn'], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testCardsWithoutSlowOrRepeatedQueriesStayNeutral(): void
    {
        $view = $this->build([$this->query('h1', 'SELECT 1')]);

        static::assertSame(['0', '0'], [$view->cards[2]->value, $view->cards[3]->value]);
        static::assertNull($view->cards[2]->tone);
        static::assertNull($view->cards[3]->tone);
    }

    public function testTheCardsTakeTheTotalsOfThePeriodAndNotTheRowsShownWhateverTheSort(): void
    {
        $rows = [$this->query('h1', 'SELECT 1', 12, 600.0, 90.0, 0), $this->query('h2', 'SELECT 2', 3, 300.0, 200.0, 2)];

        foreach (array_keys($this->screen()->sorts()) as $sort) {
            foreach (['desc', 'asc'] as $direction) {
                $reader = $this->reader($rows, keyCount: 250, series: [1, 2], totals: self::totals(500, 4200.0, 17));
                $view = $this->screen()->build($reader, Period::OneHour, self::NOW, $sort, $direction);

                static::assertSame(['500', '4.2 s', '17'], [$view->cards[0]->value, $view->cards[1]->value, $view->cards[2]->value], $sort . ' ' . $direction);
                static::assertSame('warn', $view->cards[2]->tone);
                static::assertCount(1, array_filter($reader->queries, static fn(ReportQuery $query): bool => $query->family === Family::Query));
            }
        }

        $view = $this->build($rows, totals: []);
        static::assertSame(['0', '<1 ms', '0'], [$view->cards[0]->value, $view->cards[1]->value, $view->cards[2]->value]);
    }

    public function testRepeatedQueriesShowWhereAndWhichQuery(): void
    {
        $reader = $this->reader(
            [$this->query('h1', self::SQL)],
            nPlusOne: [
                $this->nPlusOne('n1', 'GET app_home', self::QUERY_ID, 3, 40.0, 15.0, 90.0),
                $this->nPlusOne('n2', 'POST app_cart', 'fedcba9876543210', 1, 10.0, 10.0, 5.0),
                new ReportRow('n3', 'malformed', 'malformed', [Metric::NPLUS1_CALLS => new MetricValue(1, 10.0, 10.0)]),
            ],
            labels: [self::QUERY_ID => self::SQL],
        );

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'total', 'desc');

        static::assertCount(2, $view->tables);
        $table = $view->tables[1];
        static::assertSame('nplus1', $table->id);
        static::assertSame('Repeated queries (N+1)', $table->title);
        static::assertSame(
            [['where', 'Where', false, null], ['query', 'Query', false, null], ['calls', 'Calls', true, null], ['avg_repeats', 'Avg repeats', true, null], ['max_repeats', 'Max repeats', true, null], ['total', 'Total time', true, null]],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertCount(2, $table->rows);
        static::assertSame([[self::QUERY_ID, 'fedcba9876543210']], $reader->labelRequests);

        $row = $table->rows[0];
        static::assertSame('n1', $row->key);
        static::assertSame(['data-jm-nplus1' => 'GET app_home', 'data-jm-repeats-max' => '15'], $row->attributes);
        static::assertSame(
            ['where' => 'app_home', 'query' => self::SQL, 'calls' => '3', 'avg_repeats' => '13.3', 'max_repeats' => '15', 'total' => '90 ms'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('route', $row->cells['where']->kind);
        static::assertSame(['method' => 'GET'], $row->cells['where']->data);
        static::assertSame('sql', $row->cells['query']->kind);

        static::assertSame('(query text unavailable)', $table->rows[1]->cells['query']->text);
        static::assertSame('text', $table->rows[1]->cells['query']->kind);
        static::assertSame('10.0', $table->rows[1]->cells['avg_repeats']->text);
        static::assertSame('2', $view->cards[3]->value);
    }

    public function testRepeatedQueriesFoldedIntoOtherComeLast(): void
    {
        $other = new ReportRow('no', Aggregator::OTHER, null, [
            Metric::NPLUS1_CALLS => new MetricValue(4, 60.0, 20.0),
            Metric::NPLUS1_DURATION => new MetricValue(4, 120.0, 50.0),
        ]);
        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOne: [$this->nPlusOne('n1', 'GET app_home', self::QUERY_ID)], nPlusOneOther: $other);
        $table = $view->tables[1];
        $last = $table->rows[1];

        static::assertCount(2, $table->rows);
        static::assertTrue($last->muted);
        static::assertSame('no', $last->key);
        static::assertSame(['data-jm-nplus1' => Aggregator::OTHER, 'data-jm-repeats-max' => '20'], $last->attributes);
        static::assertSame(
            ['where' => 'Other repeated queries', 'calls' => '4', 'avg_repeats' => '15.0', 'max_repeats' => '20', 'total' => '120 ms'],
            array_map(static fn(Cell $cell): string => $cell->text, $last->cells),
        );
        static::assertSame('text', $last->cells['where']->kind);
        static::assertSame('2', $view->cards[3]->value);
    }

    public function testRepeatedQueriesAllFoldedIntoOtherStillShow(): void
    {
        $other = new ReportRow('no', Aggregator::OTHER, null, [Metric::NPLUS1_CALLS => new MetricValue(1, 12.0, 12.0)]);
        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOneOther: $other);

        static::assertCount(2, $view->tables);
        static::assertCount(1, $view->tables[1]->rows);
        static::assertTrue($view->tables[1]->rows[0]->muted);
        static::assertSame('1', $view->cards[3]->value);
        static::assertSame('warn', $view->cards[3]->tone);
    }

    public function testRepeatedQueriesBeyondTheLimitAreCounted(): void
    {
        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOne: [$this->nPlusOne('n1', 'GET app_home', self::QUERY_ID)], nPlusOneKeyCount: 130);

        static::assertSame(129, $view->tables[1]->remaining);
        static::assertSame('130', $view->cards[3]->value);
    }

    public function testThePanelOfRepeatedQueriesIsLeftOutWhenThereAreNone(): void
    {
        $view = $this->build([$this->query('h1', 'SELECT 1')]);

        static::assertCount(1, $view->tables);
        static::assertFalse($view->isEmpty());
        static::assertTrue($this->build([])->isEmpty());
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<int>       $series
     * @param list<ReportRow>                  $nPlusOne
     * @param array<string, MetricValue>|null $totals
     */
    private function build(
        array $rows,
        string $sort = 'total',
        ?ReportRow $other = null,
        ?int $keyCount = null,
        array $series = [],
        array $nPlusOne = [],
        ?int $nPlusOneKeyCount = null,
        Period $period = Period::OneHour,
        ?ReportRow $nPlusOneOther = null,
        ?array $totals = null,
    ): ScreenView {
        return $this->screen()->build($this->reader($rows, $other, $keyCount, $series, $nPlusOne, $nPlusOneKeyCount, [], $nPlusOneOther, $totals), $period, self::NOW, $sort, 'desc');
    }

    /**
     * @param list<ReportRow>       $rows
     * @param list<int>             $series
     * @param list<ReportRow>       $nPlusOne
     * @param array<string, string>           $labels
     * @param array<string, MetricValue>|null $totals null for the totals of the rows
     */
    private function reader(
        array $rows,
        ?ReportRow $other = null,
        ?int $keyCount = null,
        array $series = [],
        array $nPlusOne = [],
        ?int $nPlusOneKeyCount = null,
        array $labels = [],
        ?ReportRow $nPlusOneOther = null,
        ?array $totals = null,
    ): FixedReader {
        $queries = new Report($rows, $other, $keyCount ?? \count($rows), $series, $totals ?? self::rowTotals([...$rows, ...($other === null ? [] : [$other])]));
        $repeated = new Report($nPlusOne, $nPlusOneOther, $nPlusOneKeyCount ?? \count($nPlusOne));

        return new FixedReader(static fn(ReportQuery $query): Report => $query->family === Family::NPlusOne ? $repeated : $queries, $labels);
    }

    /** @return array<string, MetricValue> */
    private static function totals(int $executions, float $totalMs, int $slow): array
    {
        return [Metric::QUERY_DURATION => new MetricValue($executions, $totalMs, 0.0), Metric::QUERY_SLOW => new MetricValue($slow, 0.0, 0.0)];
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return array<string, MetricValue>
     */
    private static function rowTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            foreach ($row->metrics as $type => $value) {
                $total = $totals[$type] ?? new MetricValue(0, 0.0, 0.0);
                $totals[$type] = new MetricValue($total->count + $value->count, $total->sum + $value->sum, max($total->max, $value->max));
            }
        }

        return $totals;
    }

    private function screen(): QueriesScreen
    {
        return new QueriesScreen(true, 100, 10);
    }

    private function table(ScreenView $view): Table
    {
        return $view->tables[0];
    }

    private function query(string $hash, ?string $sql, int $count = 1, float $sumMs = 2.0, float $maxMs = 2.0, int $slow = 0): ReportRow
    {
        $metrics = [Metric::QUERY_DURATION => new MetricValue($count, $sumMs, $maxMs)];
        if ($slow > 0) {
            $metrics[Metric::QUERY_SLOW] = new MetricValue($slow, 0.0, 0.0);
        }

        return new ReportRow($hash, $sql ?? $hash, $sql, $metrics);
    }

    private function nPlusOne(string $hash, string $entryPoint, string $queryId, int $requests = 1, float $repeats = 12.0, float $maxRepeats = 12.0, float $durationMs = 24.0): ReportRow
    {
        $key = 'request' . Entry::KEY_SEPARATOR . $entryPoint . Entry::KEY_SEPARATOR . $queryId;

        return new ReportRow($hash, $key, $key, [
            Metric::NPLUS1_CALLS => new MetricValue($requests, $repeats, $maxRepeats),
            Metric::NPLUS1_DURATION => new MetricValue($requests, $durationMs, $durationMs),
        ]);
    }
}
