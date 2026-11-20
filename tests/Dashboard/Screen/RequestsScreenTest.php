<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\TestCase;

final class RequestsScreenTest extends TestCase
{
    private const NOW = 1800000000;

    public function testItReadsTheRequestFamilyWithItsSeries(): void
    {
        $reader = new FixedReader(new Report([$this->route('GET app_a')], series: array_fill(0, 60, 1)));

        $view = (new RequestsScreen())->build($reader, Period::SixHours, self::NOW, 'total', 'desc');

        static::assertCount(1, $reader->queries);
        $query = $reader->queries[0];
        static::assertSame(Family::Request, $query->family);
        static::assertSame(Period::SixHours, $query->period);
        static::assertSame(self::NOW, $query->now);
        static::assertSame(Metric::REQUEST_DURATION, $query->seriesMetric);
        static::assertSame(array_fill(0, 60, 1), $view->cards[0]->series);
    }

    public function testTheScreenDeclaresItsSortsWithTotalTimeFirst(): void
    {
        $screen = new RequestsScreen();

        static::assertSame('requests', $screen->id());
        static::assertSame('Requests', $screen->label());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['total' => 'desc', 'route' => 'asc', 'requests' => 'desc', 'avg' => 'desc', 'p95' => 'desc', 'max' => 'desc', 'sql' => 'desc', 'http' => 'desc', 'errors' => 'desc', 'slow' => 'desc', 'memory' => 'desc'],
            $screen->sorts(),
        );
        static::assertSame(
            ['route', 'requests', 'total', 'avg', 'p95', 'max', 'sql', 'http', 'errors', 'slow', 'memory'],
            array_map(static fn($column): string => $column->id, $this->table($this->build([$this->route('GET app_a')]))->columns),
        );
    }

    public function testEverySortKey(): void
    {
        $rows = [
            $this->route('POST app_b', 40, 500.0, 90.0, ['25' => 40], 2, 0, 4096, 40, 400.0, 80.0, httpRequests: 40, httpTime: 800.0),
            $this->route('GET app_a', 7, 560.0, 400.0, ['250' => 7], 0, 3, 1024, 7, 350.0, 7.0, httpRequests: 5, httpTime: 350.0),
            $this->route('GET app_d', 120, 384.0, 15.0, ['10' => 100, '25' => 20], 1, 2, 2048),
            $this->route('DELETE app_c', 1, 900.0, 900.0, ['1000' => 1], 3, 1, 8192, 1, 2.0, 1.0, httpRequests: 1, httpTime: 30.0),
        ];
        $descending = [
            'total' => ['c', 'a', 'b', 'd'],
            'route' => ['d', 'c', 'b', 'a'],
            'requests' => ['d', 'b', 'a', 'c'],
            'avg' => ['c', 'a', 'b', 'd'],
            'p95' => ['c', 'a', 'b', 'd'],
            'max' => ['c', 'a', 'b', 'd'],
            'sql' => ['a', 'b', 'c', 'd'],
            'http' => ['a', 'c', 'b', 'd'],
            'errors' => ['c', 'b', 'd', 'a'],
            'slow' => ['a', 'd', 'c', 'b'],
            'memory' => ['c', 'b', 'd', 'a'],
        ];

        static::assertSame(array_keys((new RequestsScreen())->sorts()), array_keys($descending));

        foreach ($descending as $sort => $expected) {
            static::assertSame($expected, $this->order($this->build($rows, $sort, 'desc')), $sort . ' desc');
            static::assertSame(array_reverse($expected), $this->order($this->build($rows, $sort, 'asc')), $sort . ' asc');
        }
    }

    public function testTheCellsOfARoute(): void
    {
        $view = $this->build([$this->route('GET app_a', 7, 560.0, 400.0, ['250' => 7], 0, 3, 1024, 7, 350.0, 7.0)]);
        $row = $this->table($view)->rows[0];
        $texts = array_map(static fn($cell): string => $cell->text, $row->cells);

        static::assertSame(['data-jm-route' => 'GET app_a', 'data-jm-requests' => '7'], $row->attributes);
        static::assertFalse($row->muted);
        static::assertSame(
            ['route' => 'app_a', 'requests' => '7', 'total' => '560 ms', 'avg' => '80 ms', 'p95' => '243 ms', 'max' => '400 ms', 'sql' => '50 ms · 1.0 q', 'http' => '—', 'errors' => '0', 'slow' => '3', 'memory' => '1 KB'],
            $texts,
        );
        static::assertSame('route', $row->cells['route']->kind);
        static::assertSame(['method' => 'GET'], $row->cells['route']->data);
        static::assertSame('warn', $row->cells['p95']->tone);
        static::assertSame('warn', $row->cells['max']->tone);
        static::assertSame('zero', $row->cells['errors']->tone);
        static::assertSame('warn', $row->cells['slow']->tone);
        static::assertNull($row->cells['total']->tone);
    }

    public function testHighlightsFollowErrorsAndSlowRequests(): void
    {
        $row = $this->table($this->build([$this->route('GET app_a', 100, 1000.0, 50.0, ['25' => 100], 4, 0)]))->rows[0];

        static::assertNull($row->cells['p95']->tone);
        static::assertNull($row->cells['max']->tone);
        static::assertSame('bad', $row->cells['errors']->tone);
        static::assertSame('zero', $row->cells['slow']->tone);

        $row = $this->table($this->build([$this->route('GET app_a', 100, 1000.0, 50.0, ['25' => 100], 0, 5)]))->rows[0];
        static::assertNull($row->cells['p95']->tone);
        static::assertSame('warn', $row->cells['max']->tone);
    }

    public function testARouteNameContainingSpacesKeepsItsMethodApart(): void
    {
        $row = $this->table($this->build([$this->route('GET (unmatched)'), $this->route('OTHER app_hello')]))->rows;

        static::assertSame('(unmatched)', $row[0]->cells['route']->text);
        static::assertSame(['method' => 'GET'], $row[0]->cells['route']->data);
        static::assertSame(['method' => 'OTHER'], $row[1]->cells['route']->data);
    }

    public function testTheCardsSummariseEveryRoute(): void
    {
        $view = $this->build([
            $this->route('GET app_home', 3, 120.0, 60.0, ['25' => 1, '50' => 1, '100' => 1], 1),
            $this->route('POST app_cart_add', 1, 1500.0, 1500.0, ['2500' => 1]),
        ], period: Period::SixHours);

        static::assertSame(['Requests', 'Avg duration', 'p95', '5xx errors'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['4', '405 ms', '25 %'], [$view->cards[0]->value, $view->cards[1]->value, $view->cards[3]->value]);
        static::assertSame(['Last 6 hours', 'Mean response time', '95 % of requests are faster', 'Share of server errors'], array_map(static fn(Card $card): string => $card->hint, $view->cards));
        static::assertTrue($view->cards[0]->wide);
        static::assertSame('bad', $view->cards[3]->tone);
        static::assertNull($view->cards[1]->tone);
        static::assertMatchesRegularExpression('/^\d+ ms$|^1\.\d s$/', $view->cards[2]->value);
        static::assertNotSame('<1 ms', $view->cards[2]->value);
    }

    public function testNoErrorLeavesTheErrorCardNeutral(): void
    {
        $view = $this->build([$this->route('GET app_home')]);

        static::assertSame('0 %', $view->cards[3]->value);
        static::assertNull($view->cards[3]->tone);
    }

    public function testTheSqlColumnShowsTimeAndQueriesPerRequest(): void
    {
        $rows = $this->table($this->build([
            $this->route('GET app_sql', 10, 1000.0, 200.0, sqlRequests: 8, sqlTime: 450.0, sqlCount: 120.0),
            $this->route('GET app_light', 4, 100.0, 50.0, sqlRequests: 4, sqlTime: 2.0, sqlCount: 6.0),
            $this->route('GET app_none', 1, 10.0, 10.0),
        ], 'total', 'desc'))->rows;

        static::assertSame('45 ms · 12 q', $rows[0]->cells['sql']->text);
        static::assertNull($rows[0]->cells['sql']->tone);
        static::assertSame('<1 ms · 1.5 q', $rows[1]->cells['sql']->text);
        static::assertSame('—', $rows[2]->cells['sql']->text);
        static::assertSame('zero', $rows[2]->cells['sql']->tone);
    }

    public function testTheHttpOutColumnShowsTheMeanTimePerRequest(): void
    {
        $rows = $this->table($this->build([
            $this->route('GET app_api', 10, 1000.0, 200.0, httpRequests: 8, httpTime: 450.0),
            $this->route('GET app_light', 4, 100.0, 50.0, httpRequests: 1, httpTime: 2.0),
            $this->route('GET app_none', 1, 10.0, 10.0, sqlRequests: 1, sqlTime: 3.0, sqlCount: 2.0),
        ], 'total', 'desc'))->rows;

        static::assertSame('45 ms', $rows[0]->cells['http']->text);
        static::assertNull($rows[0]->cells['http']->tone);
        static::assertSame('<1 ms', $rows[1]->cells['http']->text);
        static::assertSame('—', $rows[2]->cells['http']->text);
        static::assertSame('zero', $rows[2]->cells['http']->tone);
        static::assertSame('3 ms · 2.0 q', $rows[2]->cells['sql']->text);
    }

    public function testOtherRoutesComeLastWhateverTheSort(): void
    {
        $rows = [$this->route('GET app_a', 2, 20.0, 10.0), $this->route('GET app_b', 3, 30.0, 10.0)];
        $other = new ReportRow('h', Aggregator::OTHER, null, $this->metrics(50, 5000.0, 900.0));

        foreach ([['total', 'desc'], ['total', 'asc'], ['route', 'asc'], ['requests', 'desc']] as [$sort, $direction]) {
            $view = $this->build($rows, $sort, $direction, $other);
            $last = $this->table($view)->rows[2];

            static::assertCount(3, $this->table($view)->rows);
            static::assertTrue($last->muted);
            static::assertSame('Other routes', $last->cells['route']->text);
            static::assertSame('text', $last->cells['route']->kind);
            static::assertSame('50', $last->cells['requests']->text);
            static::assertSame('55', $view->cards[0]->value);
        }

        static::assertFalse($this->build([], other: $other)->isEmpty());
        static::assertTrue($this->build([])->isEmpty());
    }

    public function testTheTableShowsAHundredRoutesAndCountsTheOthers(): void
    {
        $rows = [];
        for ($i = 1; $i <= 105; ++$i) {
            $rows[] = $this->route(sprintf('GET app_%03d', $i), 1, (float) $i, (float) $i);
        }

        $table = $this->table($this->build($rows));

        static::assertCount(100, $table->rows);
        static::assertSame(5, $table->remaining);
        static::assertSame('route', $table->noun);
        static::assertSame('GET app_105', $table->rows[0]->attributes['data-jm-route']);
        static::assertSame('GET app_006', $table->rows[99]->attributes['data-jm-route']);
    }

    /**
     * @param list<ReportRow> $rows
     */
    private function build(array $rows, string $sort = 'total', string $direction = 'desc', ?ReportRow $other = null, Period $period = Period::OneHour): ScreenView
    {
        return (new RequestsScreen())->build(new FixedReader(new Report($rows, $other, \count($rows), array_fill(0, 60, 0))), $period, self::NOW, $sort, $direction);
    }

    private function table(ScreenView $view): Table
    {
        return $view->tables[0];
    }

    /** @return list<string> the last letter of each route name, in display order */
    private function order(ScreenView $view): array
    {
        return array_map(static fn(Row $row): string => substr($row->attributes['data-jm-route'], -1), $this->table($view)->rows);
    }

    /**
     * @param array<string, int> $buckets
     */
    private function route(
        string $key,
        int $requests = 1,
        float $totalMs = 10.0,
        float $maxMs = 10.0,
        array $buckets = ['10' => 1],
        int $errors = 0,
        int $slow = 0,
        int $memory = 1024,
        int $sqlRequests = 0,
        float $sqlTime = 0.0,
        float $sqlCount = 0.0,
        int $httpRequests = 0,
        float $httpTime = 0.0,
    ): ReportRow {
        $metrics = $this->metrics($requests, $totalMs, $maxMs);
        $metrics[Metric::REQUEST_MEMORY] = new MetricValue($requests, (float) ($memory * $requests), (float) $memory);
        foreach ($buckets as $bound => $count) {
            $metrics[Metric::REQUEST_DURATION_LE_PREFIX . $bound] = new MetricValue($count, 0.0, 0.0);
        }
        if ($errors > 0) {
            $metrics[Metric::REQUEST_STATUS_PREFIX . '5xx'] = new MetricValue($errors, 0.0, 0.0);
        }
        if ($slow > 0) {
            $metrics[Metric::REQUEST_SLOW] = new MetricValue($slow, 0.0, 0.0);
        }
        if ($sqlRequests > 0) {
            $metrics[Metric::REQUEST_SQL_TIME] = new MetricValue($sqlRequests, $sqlTime, $sqlTime);
            $metrics[Metric::REQUEST_SQL_COUNT] = new MetricValue($sqlRequests, $sqlCount, $sqlCount);
        }
        if ($httpRequests > 0) {
            $metrics[Metric::REQUEST_HTTP_TIME] = new MetricValue($httpRequests, $httpTime, $httpTime);
        }

        return new ReportRow(md5($key), $key, $key, $metrics);
    }

    /** @return array<string, MetricValue> */
    private function metrics(int $requests, float $totalMs, float $maxMs): array
    {
        return [Metric::REQUEST_DURATION => new MetricValue($requests, $totalMs, $maxMs)];
    }
}
