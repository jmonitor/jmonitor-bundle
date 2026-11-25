<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Section\CacheSection;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\TestCase;

final class CacheSectionTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheSectionDeclaresItself(): void
    {
        $screen = new CacheSection(true);

        static::assertSame('cache', $screen->id());
        static::assertSame('Cache', $screen->title());
        static::assertSame('What your code keeps aside to avoid computing or fetching it again.', $screen->summary());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['reads' => 'desc', 'pool' => 'asc', 'hits' => 'desc', 'misses' => 'desc', 'rate' => 'desc', 'writes' => 'desc', 'deletes' => 'desc'],
            $screen->sorts(),
        );
        static::assertSame([
            'title' => 'No cache read or write in the last hour.',
            'text' => 'Reads and writes on cache.app and on the pools your application declares show up here within a few seconds.',
        ], $screen->emptyState(Period::OneHour));
        static::assertSame('No cache read or write in the last 6 hours.', $screen->emptyState(Period::SixHours)['title']);
    }

    public function testTheScreenIsUnavailableWithoutTheCapture(): void
    {
        static::assertFalse((new CacheSection(false))->isAvailable());
    }

    public function testTheScreenReadsTheWholeFamilyOnce(): void
    {
        foreach (array_keys((new CacheSection(true))->sorts()) as $sort) {
            $reader = new FixedReader();
            (new CacheSection(true))->build($reader, Period::SixHours, self::NOW, $sort, 'asc', new RaisedChecks([], [], Period::OneHour));

            static::assertCount(1, $reader->queries, $sort);
            $query = $reader->queries[0];
            static::assertSame([Family::Cache, Period::SixHours, self::NOW, null], [$query->family, $query->period, $query->now, $query->seriesMetric]);
            static::assertSame([], $reader->labelRequests);
        }
    }

    public function testTheCellsOfAPool(): void
    {
        $table = $this->build([self::pool('h1', 'cache.app', 30, 10, 4, 2)])->tables[0];
        $row = $table->rows[0];

        static::assertSame('pools', $table->id);
        static::assertSame('Pools', $table->title);
        static::assertSame(
            [
                ['pool', 'Pool', false, 'pool'],
                ['hits', 'Hits', true, 'hits'],
                ['misses', 'Misses', true, 'misses'],
                ['rate', 'Hit rate', true, 'rate'],
                ['writes', 'Writes', true, 'writes'],
                ['deletes', 'Deletes', true, 'deletes'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-pool' => 'cache.app', 'data-jm-reads' => '40'], $row->attributes);
        static::assertSame(
            ['pool' => 'cache.app', 'hits' => '30', 'misses' => '10', 'rate' => '75 %', 'writes' => '4', 'deletes' => '2'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('code', $row->cells['pool']->kind);
        static::assertNull($row->cells['rate']->tone);
    }

    public function testALowHitRateIsHighlightedOnceThereAreEnoughReads(): void
    {
        $rows = $this->build([
            self::pool('h1', 'app.cold', 9, 11),
            self::pool('h2', 'app.young', 1, 18),
            self::pool('h3', 'app.half', 10, 10),
            self::pool('h4', 'app.written', 0, 0, 5, 1),
        ])->tables[0]->rows;
        $rate = static fn(Row $row): array => [$row->cells['rate']->text, $row->cells['rate']->tone];

        static::assertSame(['app.cold', 'app.half', 'app.young', 'app.written'], array_map(static fn(Row $row): string => $row->cells['pool']->text, $rows));
        static::assertSame(['45 %', 'warn'], $rate($rows[0]));
        static::assertSame(['50 %', null], $rate($rows[1]));
        static::assertSame(['5.3 %', null], $rate($rows[2]));
        static::assertSame(['—', null], $rate($rows[3]));
        static::assertSame(['0', '0', '5', '1'], array_map(static fn(string $id): string => $rows[3]->cells[$id]->text, ['hits', 'misses', 'writes', 'deletes']));
        static::assertSame('0', $rows[3]->attributes['data-jm-reads']);
    }

    public function testTheSorts(): void
    {
        $rows = [
            self::pool('h1', 'cache.app', 30, 10, 1, 5),
            self::pool('h2', 'app.users', 2, 30, 9, 0),
            self::pool('h3', 'app.empty', 0, 0, 3, 1),
            self::pool('h4', 'app.hot', 50, 0, 0, 2),
        ];

        $expected = [
            'reads desc' => ['app.hot', 'cache.app', 'app.users', 'app.empty'],
            'reads asc' => ['app.empty', 'app.users', 'cache.app', 'app.hot'],
            'pool asc' => ['app.empty', 'app.hot', 'app.users', 'cache.app'],
            'pool desc' => ['cache.app', 'app.users', 'app.hot', 'app.empty'],
            'hits desc' => ['app.hot', 'cache.app', 'app.users', 'app.empty'],
            'misses desc' => ['app.users', 'cache.app', 'app.empty', 'app.hot'],
            'rate desc' => ['app.hot', 'cache.app', 'app.users', 'app.empty'],
            'rate asc' => ['app.empty', 'app.users', 'cache.app', 'app.hot'],
            'writes desc' => ['app.users', 'app.empty', 'cache.app', 'app.hot'],
            'deletes desc' => ['cache.app', 'app.hot', 'app.empty', 'app.users'],
        ];

        foreach ($expected as $order => $pools) {
            [$sort, $direction] = explode(' ', $order);
            $view = (new CacheSection(true))->build($this->reader($rows), Period::OneHour, self::NOW, $sort, $direction, new RaisedChecks([], [], Period::OneHour));

            static::assertSame($pools, array_map(static fn(Row $row): string => $row->cells['pool']->text, $view->tables[0]->rows), $order);
        }
    }

    public function testOtherPoolsComeLast(): void
    {
        $other = self::pool('ho', Aggregator::OTHER, 100, 50, 7, 3);

        $table = $this->build([self::pool('h1', 'cache.app', 1, 1)], $other)->tables[0];

        static::assertCount(2, $table->rows);
        $last = $table->rows[1];
        static::assertTrue($last->muted);
        static::assertSame('ho', $last->key);
        static::assertSame(
            ['pool' => 'Other pools', 'hits' => '100', 'misses' => '50', 'rate' => '67 %', 'writes' => '7', 'deletes' => '3'],
            array_map(static fn(Cell $cell): string => $cell->text, $last->cells),
        );
        static::assertSame('text', $last->cells['pool']->kind);
        static::assertSame(0, $table->remaining);
    }

    public function testTheCards(): void
    {
        $view = $this->build([
            self::pool('h1', 'cache.app', 12, 3, 3, 1),
            self::pool('h2', 'app.users', 2, 23, 20, 0),
        ], self::pool('ho', Aggregator::OTHER, 1, 1, 1, 4), Period::OneDay);

        static::assertSame(['Reads', 'Hit rate', 'Writes', 'Deletes'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['42', '36 %', '24', '5'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame('Last 24 hours', $view->cards[0]->hint);
        static::assertSame('warn', $view->cards[1]->tone);
        static::assertSame([null, null, null], [$view->cards[0]->tone, $view->cards[2]->tone, $view->cards[3]->tone]);
    }

    public function testCardsWithoutReadsShowNoRate(): void
    {
        $view = $this->build([self::pool('h1', 'cache.app', 0, 0, 2, 0)]);

        static::assertSame(['0', '—', '2', '0'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertNull($view->cards[1]->tone);
    }

    public function testAnEmptyPeriodShowsTheEmptyState(): void
    {
        static::assertTrue($this->build([])->isEmpty());
    }

    /**
     * @param list<ReportRow> $rows
     */
    private function build(array $rows, ?ReportRow $other = null, Period $period = Period::OneHour): ScreenView
    {
        return (new CacheSection(true))->build($this->reader($rows, $other), $period, self::NOW, 'reads', 'desc', new RaisedChecks([], [], Period::OneHour));
    }

    /**
     * @param list<ReportRow> $rows
     */
    private function reader(array $rows, ?ReportRow $other = null): FixedReader
    {
        $totals = [];
        foreach ([...$rows, ...($other === null ? [] : [$other])] as $row) {
            foreach ($row->metrics as $type => $value) {
                $totals[$type] = new MetricValue(($totals[$type]->count ?? 0) + $value->count, 0.0, 0.0);
            }
        }

        return new FixedReader(static fn(ReportQuery $query): Report => new Report($rows, $other, \count($rows), [], $totals));
    }

    private static function pool(string $hash, string $name, int $hits, int $misses, int $writes = 0, int $deletes = 0): ReportRow
    {
        $metrics = array_filter([
            Metric::CACHE_HIT => $hits,
            Metric::CACHE_MISS => $misses,
            Metric::CACHE_WRITE => $writes,
            Metric::CACHE_DELETE => $deletes,
        ]);

        return new ReportRow($hash, $name, $name, array_map(static fn(int $count): MetricValue => new MetricValue($count, 0.0, 0.0), $metrics));
    }
}
