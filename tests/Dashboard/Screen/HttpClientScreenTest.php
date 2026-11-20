<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Screen\HttpClientScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\TestCase;

final class HttpClientScreenTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheScreenDeclaresItself(): void
    {
        $screen = new HttpClientScreen(true);

        static::assertSame('http-client', $screen->id());
        static::assertSame('HTTP client', $screen->label());
        static::assertSame('Outgoing calls made with the Symfony HttpClient, per host', $screen->heading());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['total' => 'desc', 'calls' => 'desc', 'avg' => 'desc', 'max' => 'desc', '4xx' => 'desc', '5xx' => 'desc', 'failed' => 'desc'],
            $screen->sorts(),
        );
        static::assertSame(
            ['title' => 'No outgoing calls recorded yet', 'text' => 'Calls made with the Symfony HttpClient show up here within a few seconds.'],
            $screen->emptyState(),
        );
    }

    public function testTheScreenIsUnavailableWithoutTheCapture(): void
    {
        static::assertFalse((new HttpClientScreen(false))->isAvailable());
    }

    public function testEverySortIsComputedByTheDatabase(): void
    {
        $sorts = [
            'total' => [Metric::HTTP_DURATION, 'sum'],
            'calls' => [Metric::HTTP_DURATION, 'count'],
            'avg' => [Metric::HTTP_DURATION, 'avg'],
            'max' => [Metric::HTTP_DURATION, 'max'],
            '4xx' => [Metric::HTTP_STATUS_PREFIX . '4xx', 'count'],
            '5xx' => [Metric::HTTP_STATUS_PREFIX . '5xx', 'count'],
            'failed' => [Metric::HTTP_FAILED, 'count'],
        ];

        static::assertSame(array_keys((new HttpClientScreen(true))->sorts()), array_keys($sorts));

        foreach ($sorts as $sort => [$metric, $field]) {
            foreach (['desc' => true, 'asc' => false] as $direction => $descending) {
                $reader = new FixedReader();
                (new HttpClientScreen(true))->build($reader, Period::SixHours, self::NOW, (string) $sort, $direction);

                static::assertCount(1, $reader->queries);
                $query = $reader->queries[0];
                static::assertSame(Family::Http, $query->family);
                static::assertSame(Period::SixHours, $query->period);
                static::assertSame(self::NOW, $query->now);
                static::assertSame($metric, $query->sortMetric, (string) $sort);
                static::assertSame($field, $query->sortField, (string) $sort);
                static::assertSame($descending, $query->descending, $sort . ' ' . $direction);
                static::assertSame(100, $query->limit);
                static::assertSame(Metric::HTTP_DURATION, $query->seriesMetric);
                static::assertSame([], $reader->labelRequests);
            }
        }

        $reader = new FixedReader();
        (new HttpClientScreen(true))->build($reader, Period::OneHour, self::NOW, 'unknown', 'desc');
        static::assertSame([Metric::HTTP_DURATION, 'sum'], [$reader->queries[0]->sortMetric, $reader->queries[0]->sortField]);
    }

    public function testTheCellsOfAHost(): void
    {
        $table = $this->build([self::host('h1', 'api.example.com:8443', 12, 3000.0, 900.0, 2, 1, 3)])->tables[0];
        $row = $table->rows[0];

        static::assertSame('hosts', $table->id);
        static::assertSame('Hosts', $table->title);
        static::assertSame('host', $table->noun);
        static::assertSame(
            [
                ['host', 'Host', false, null],
                ['calls', 'Calls', true, 'calls'],
                ['total', 'Total time', true, 'total'],
                ['avg', 'Avg', true, 'avg'],
                ['max', 'Max', true, 'max'],
                ['4xx', '4xx', true, '4xx'],
                ['5xx', '5xx', true, '5xx'],
                ['failed', 'Failed', true, 'failed'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-host' => 'api.example.com:8443', 'data-jm-calls' => '12'], $row->attributes);
        static::assertSame(
            ['host' => 'api.example.com:8443', 'calls' => '12', 'total' => '3.0 s', 'avg' => '250 ms', 'max' => '900 ms', '4xx' => '2', '5xx' => '1', 'failed' => '3'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame([null, 'warn', 'bad', 'bad'], [$row->cells['total']->tone, $row->cells['4xx']->tone, $row->cells['5xx']->tone, $row->cells['failed']->tone]);
    }

    public function testAHostWithoutErrorsIsNotHighlighted(): void
    {
        $row = $this->build([self::host('h1', 'api.example.com')])->tables[0]->rows[0];

        static::assertSame(['0', '0', '0'], [$row->cells['4xx']->text, $row->cells['5xx']->text, $row->cells['failed']->text]);
        static::assertSame(['zero', 'zero', 'zero'], [$row->cells['4xx']->tone, $row->cells['5xx']->tone, $row->cells['failed']->tone]);
    }

    public function testOtherHostsComeLastAndTheRestIsCounted(): void
    {
        $other = self::host('ho', Aggregator::OTHER, 40, 800.0);
        $table = $this->build([self::host('h1', 'a.example'), self::host('h2', 'b.example')], other: $other, keyCount: 75)->tables[0];

        static::assertSame(['h1', 'h2', 'ho'], array_map(static fn(Row $row): string => $row->key, $table->rows));
        $last = $table->rows[2];
        static::assertTrue($last->muted);
        static::assertSame('Other hosts', $last->cells['host']->text);
        static::assertSame('40', $last->cells['calls']->text);
        static::assertSame(['data-jm-host' => Aggregator::OTHER, 'data-jm-calls' => '40'], $last->attributes);
        static::assertSame(73, $table->remaining);
    }

    public function testTheCards(): void
    {
        $view = $this->build(
            [self::host('h1', 'a.example', 12, 3000.0, 900.0, 2, 1, 3)],
            series: [3, 0, 7],
            period: Period::OneDay,
            totals: self::totals(250, 10000.0, 6, 4, 5),
        );

        static::assertSame(['Calls', 'Time in calls', 'Error responses', 'Network failures'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['250', '10 s', '10', '5'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame(
            ['Last 24 hours', 'Avg 40 ms per call', 'Responses with a 4xx or 5xx status', 'Calls that got no response'],
            array_map(static fn(Card $card): string => $card->hint, $view->cards),
        );
        static::assertSame([3, 0, 7], $view->cards[0]->series);
        static::assertTrue($view->cards[0]->wide);
        static::assertSame([null, null, 'warn', 'bad'], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testCardsWithoutErrorsStayNeutral(): void
    {
        $view = $this->build([self::host('h1', 'a.example')]);

        static::assertSame(['1', '20 ms', '0', '0'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame([null, null, null, null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testAnEmptyPeriodShowsTheEmptyState(): void
    {
        static::assertTrue($this->build([])->isEmpty());
        static::assertFalse($this->build([self::host('h1', 'a.example')])->isEmpty());
    }

    /**
     * @param list<ReportRow>                 $rows
     * @param list<int>                       $series
     * @param array<string, MetricValue>|null $totals null for the totals of the rows
     */
    private function build(
        array $rows,
        ?ReportRow $other = null,
        ?int $keyCount = null,
        array $series = [],
        Period $period = Period::OneHour,
        ?array $totals = null,
    ): ScreenView {
        $all = [...$rows, ...($other === null ? [] : [$other])];
        $totals ??= self::totals(
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::HTTP_DURATION), $all)),
            array_sum(array_map(static fn(ReportRow $row): float => $row->sum(Metric::HTTP_DURATION), $all)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::HTTP_STATUS_PREFIX . '4xx'), $all)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::HTTP_STATUS_PREFIX . '5xx'), $all)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::HTTP_FAILED), $all)),
        );
        $reader = new FixedReader(new Report($rows, $other, $keyCount ?? \count($rows), $series, $all === [] ? [] : $totals));

        return (new HttpClientScreen(true))->build($reader, $period, self::NOW, 'total', 'desc');
    }

    /** @return array<string, MetricValue> */
    private static function totals(int $calls, float $totalMs, int $clientErrors, int $serverErrors, int $failures): array
    {
        return [
            Metric::HTTP_DURATION => new MetricValue($calls, $totalMs, 0.0),
            Metric::HTTP_STATUS_PREFIX . '4xx' => new MetricValue($clientErrors, 0.0, 0.0),
            Metric::HTTP_STATUS_PREFIX . '5xx' => new MetricValue($serverErrors, 0.0, 0.0),
            Metric::HTTP_FAILED => new MetricValue($failures, 0.0, 0.0),
        ];
    }

    private static function host(
        string $hash,
        string $host,
        int $calls = 1,
        float $totalMs = 20.0,
        float $maxMs = 20.0,
        int $clientErrors = 0,
        int $serverErrors = 0,
        int $failures = 0,
    ): ReportRow {
        $metrics = [Metric::HTTP_DURATION => new MetricValue($calls, $totalMs, $maxMs)];
        foreach ([Metric::HTTP_STATUS_PREFIX . '4xx' => $clientErrors, Metric::HTTP_STATUS_PREFIX . '5xx' => $serverErrors, Metric::HTTP_FAILED => $failures] as $metric => $count) {
            if ($count > 0) {
                $metrics[$metric] = new MetricValue($count, 0.0, 0.0);
            }
        }

        return new ReportRow($hash, $host, $host, $metrics);
    }
}
