<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DbalReportTest extends TestCase
{
    private const NOW = 1700003600;

    /** @return array<string, array{Connection}> */
    public static function connections(): array
    {
        return ConnectionProvider::all();
    }

    /** @return iterable<string, array{Connection, string, bool, string}> */
    public static function sorts(): iterable
    {
        foreach (ConnectionProvider::all() as $name => [$connection]) {
            yield $name . ' sum desc' => [$connection, 'sum', true, 'c'];
            yield $name . ' count desc' => [$connection, 'count', true, 'a'];
            yield $name . ' max desc' => [$connection, 'max', true, 'b'];
            yield $name . ' avg desc' => [$connection, 'avg', true, 'e'];
            yield $name . ' sum asc' => [$connection, 'sum', false, 'd'];
            yield $name . ' count asc' => [$connection, 'count', false, 'e'];
        }
    }

    #[DataProvider('connections')]
    public function testAClosedFamilyComesWholeWithAllItsMetrics(Connection $connection): void
    {
        $aggregates = [];
        foreach (['GET app_home', 'POST app_cart_add', 'GET (unmatched)'] as $key) {
            $aggregates[] = $this->aggregate(Metric::REQUEST_DURATION, $key, 3, 400.0, 300.0);
            $aggregates[] = $this->aggregate(Metric::REQUEST_STATUS_PREFIX . '5xx', $key, 1, 0.0, 0.0);
            $aggregates[] = $this->aggregate(Metric::REQUEST_DURATION_LE_PREFIX . '50', $key, 2, 0.0, 0.0);
            $aggregates[] = $this->aggregate(Metric::REQUEST_DURATION_LE_PREFIX . '500', $key, 1, 0.0, 0.0);
        }
        $aggregates[] = $this->aggregate(Metric::QUERY_DURATION, 'q1', 1, 1.0, 1.0);
        $this->seed($connection, $aggregates);

        $report = (new DbalReader($connection))->read(new ReportQuery(Period::OneHour, self::NOW, Family::Request, Metric::REQUEST_DURATION, 'sum', true, 1));

        $rows = $this->indexByKey($report->rows);
        static::assertSame(['GET (unmatched)', 'GET app_home', 'POST app_cart_add'], array_keys($rows));
        static::assertSame(3, $report->keyCount);
        static::assertNull($report->other);
        foreach ($rows as $key => $row) {
            static::assertSame($key, $row->label);
            static::assertSame(AggregatesTable::keyHash($key), $row->hash);
            static::assertSame(3, $row->count(Metric::REQUEST_DURATION));
            static::assertSame(400.0, $row->sum(Metric::REQUEST_DURATION));
            static::assertSame(300.0, $row->max(Metric::REQUEST_DURATION));
            static::assertSame(1, $row->count(Metric::REQUEST_STATUS_PREFIX . '5xx'));
            static::assertSame(0, $row->count(Metric::REQUEST_SLOW));
            static::assertSame(['50' => 2, '500' => 1], $row->countsByPrefix(Metric::REQUEST_DURATION_LE_PREFIX));
        }
    }

    #[DataProvider('connections')]
    public function testTheOtherRowOfAClosedFamilyIsSetApart(Connection $connection): void
    {
        $this->seed($connection, [
            $this->aggregate(Metric::REQUEST_DURATION, 'GET app_home', 1, 10.0, 10.0),
            $this->aggregate(Metric::REQUEST_DURATION, Aggregator::OTHER, 5, 50.0, 20.0),
        ]);

        $report = (new DbalReader($connection))->read(new ReportQuery(Period::OneHour, self::NOW, Family::Request));

        static::assertSame(['GET app_home'], array_keys($this->indexByKey($report->rows)));
        static::assertSame(1, $report->keyCount);
        static::assertSame(Aggregator::OTHER, $report->other?->key);
        static::assertSame(AggregatesTable::keyHash(Aggregator::OTHER), $report->other->hash);
        static::assertSame(5, $report->other->count(Metric::REQUEST_DURATION));
    }

    #[DataProvider('connections')]
    public function testAnOpenFamilyIsCutByTheDatabase(Connection $connection): void
    {
        $aggregates = [];
        for ($i = 1; $i <= 150; ++$i) {
            $aggregates[] = $this->aggregate(Metric::QUERY_DURATION, 'q' . $i, 1, (float) $i, (float) $i);
            if ($i % 2 === 0) {
                $aggregates[] = $this->aggregate(Metric::QUERY_SLOW, 'q' . $i, 1, 0.0, 0.0);
            }
        }
        $aggregates[] = $this->aggregate(Metric::QUERY_DURATION, Aggregator::OTHER, 1000, 1_000_000.0, 5000.0);
        $this->seed($connection, $aggregates);

        $report = (new DbalReader($connection))->read(new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_DURATION));

        static::assertCount(100, $report->rows);
        static::assertSame('q150', $report->rows[0]->key);
        static::assertSame('q51', $report->rows[99]->key);
        static::assertSame(150, $report->keyCount);
        static::assertSame(1_000_000.0, $report->other?->sum(Metric::QUERY_DURATION));
        static::assertSame(AggregatesTable::keyHash(Aggregator::OTHER), $report->other->hash);
        foreach ($report->rows as $row) {
            $i = (int) substr($row->key, 1);
            static::assertSame($row->key, $row->label);
            static::assertSame(AggregatesTable::keyHash($row->key), $row->hash);
            static::assertSame((float) $i, $row->sum(Metric::QUERY_DURATION));
            static::assertSame($i % 2 === 0 ? 1 : 0, $row->count(Metric::QUERY_SLOW));
        }
    }

    #[DataProvider('sorts')]
    public function testAnOpenFamilySortsByCountMaxAverageAndAscending(Connection $connection, string $field, bool $descending, string $firstKey): void
    {
        $this->seed($connection, [
            $this->aggregate(Metric::QUERY_DURATION, 'a', 10, 100.0, 15.0),
            $this->aggregate(Metric::QUERY_DURATION, 'b', 4, 80.0, 75.0),
            $this->aggregate(Metric::QUERY_DURATION, 'c', 2, 60.0, 40.0),
            $this->aggregate(Metric::QUERY_DURATION, 'c', 3, 90.0, 30.0, 600),
            $this->aggregate(Metric::QUERY_DURATION, 'd', 2, 4.0, 3.0),
            $this->aggregate(Metric::QUERY_DURATION, 'e', 1, 60.0, 60.0),
            $this->aggregate(Metric::QUERY_SLOW, 'd', 50, 0.0, 0.0),
            $this->aggregate(Metric::QUERY_DURATION, Aggregator::OTHER, 1000, 1_000_000.0, 5000.0),
        ]);

        $report = (new DbalReader($connection))->read(new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_DURATION, $field, $descending, 3));

        static::assertCount(3, $report->rows);
        static::assertSame($firstKey, $report->rows[0]->key);
        static::assertSame(5, $report->keyCount);
        static::assertNotNull($report->other);
    }

    #[DataProvider('connections')]
    public function testRowsOutsideThePeriodAreNotRead(Connection $connection): void
    {
        $current = Period::OneHour->bucketFor(self::NOW);
        $this->seed($connection, [
            $this->aggregate(Metric::QUERY_DURATION, 'old', 1, 1.0, 1.0, self::NOW - $current + 3600),
            $this->aggregate(Metric::QUERY_DURATION, 'oldest', 1, 1.0, 1.0, 7200),
            $this->aggregate(Metric::QUERY_DURATION, 'edge', 1, 1.0, 1.0, self::NOW - $current + 3540),
            $this->aggregate(Metric::REQUEST_DURATION, 'GET old', 1, 1.0, 1.0, self::NOW - $current + 3600),
            $this->aggregate(Metric::REQUEST_DURATION, 'GET edge', 1, 1.0, 1.0, self::NOW - $current + 3540),
        ]);
        $reader = new DbalReader($connection);

        $open = $reader->read(new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_DURATION, seriesMetric: Metric::QUERY_DURATION));
        $closed = $reader->read(new ReportQuery(Period::OneHour, self::NOW, Family::Request));

        static::assertSame(['edge'], array_keys($this->indexByKey($open->rows)));
        static::assertSame(1, $open->keyCount);
        static::assertSame(1, array_sum($open->series));
        static::assertSame(['GET edge'], array_keys($this->indexByKey($closed->rows)));
    }

    #[DataProvider('connections')]
    public function testTheSeriesCountsTheSeriesMetricPerBucket(Connection $connection): void
    {
        $this->seed($connection, [
            $this->aggregate(Metric::QUERY_DURATION, 'a', 2, 10.0, 5.0),
            $this->aggregate(Metric::QUERY_DURATION, 'b', 1, 10.0, 5.0),
            $this->aggregate(Metric::QUERY_DURATION, 'a', 4, 10.0, 5.0, 600),
            $this->aggregate(Metric::QUERY_DURATION, Aggregator::OTHER, 5, 10.0, 5.0, 600),
            $this->aggregate(Metric::QUERY_SLOW, 'a', 7, 0.0, 0.0),
        ]);
        $reader = new DbalReader($connection);

        $series = $reader->read(new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_DURATION, seriesMetric: Metric::QUERY_DURATION))->series;

        static::assertCount(60, $series);
        static::assertSame(3, $series[59]);
        static::assertSame(9, $series[49]);
        static::assertSame(12, array_sum($series));
        static::assertSame([], $reader->read(new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_DURATION))->series);
    }

    #[DataProvider('connections')]
    public function testAKeyWithoutALabelHasNone(Connection $connection): void
    {
        $this->seed($connection, [$this->aggregate(Metric::QUERY_DURATION, 'abc', 1, 1.0, 1.0)], ['abc' => null]);

        $report = (new DbalReader($connection))->read(new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_DURATION));

        static::assertCount(1, $report->rows);
        static::assertNull($report->rows[0]->label);
        static::assertSame(AggregatesTable::keyHash('abc'), $report->rows[0]->key);
        static::assertSame(AggregatesTable::keyHash('abc'), $report->rows[0]->hash);
        static::assertSame(1, $report->rows[0]->count(Metric::QUERY_DURATION));
    }

    #[DataProvider('connections')]
    public function testLabelsAreReadByKey(Connection $connection): void
    {
        $this->seed($connection, [$this->aggregate(Metric::QUERY_DURATION, 'abc', 1, 1.0, 1.0)], ['abc' => 'SELECT 1']);
        $reader = new DbalReader($connection);

        static::assertSame(['abc' => 'SELECT 1'], $reader->labels(['abc', 'nope']));
        static::assertSame([], $reader->labels([]));
        $row = $reader->read(new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_DURATION))->rows[0];
        static::assertSame('SELECT 1', $row->label);
        static::assertSame('SELECT 1', $row->key);
        static::assertSame(AggregatesTable::keyHash('abc'), $row->hash);
    }

    #[DataProvider('connections')]
    public function testNothingIsReadWithoutTheTables(Connection $connection): void
    {
        ConnectionProvider::dropTables($connection);
        $reader = new DbalReader($connection);

        static::assertEquals(new Report(), $reader->read(new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_DURATION, seriesMetric: Metric::QUERY_DURATION)));
        static::assertEquals(new Report(), $reader->read(new ReportQuery(Period::OneHour, self::NOW, Family::Request)));
        static::assertSame([], $reader->labels(['abc']));
    }

    public function testAnOpenFamilyNeedsASortMetric(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReportQuery(Period::OneHour, self::NOW, Family::Query);
    }

    public function testTheSortFieldIsOneOfTheKnownFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_DURATION, 'value_sum) --');
    }

    /**
     * @param list<Aggregate>        $aggregates
     * @param array<string, ?string> $labels
     */
    private function seed(Connection $connection, array $aggregates, array $labels = []): void
    {
        ConnectionProvider::dropTables($connection);
        (new DbalStorage($connection, 604800))->store($aggregates, self::NOW, $labels);
    }

    private function aggregate(string $type, string $key, int $count, float $sum, float $max, int $age = 10): Aggregate
    {
        return new Aggregate(Period::OneHour, Period::OneHour->bucketFor(self::NOW - $age), $type, $key, $count, $sum, $max);
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return array<string, ReportRow>
     */
    private function indexByKey(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->key] = $row;
        }
        ksort($indexed, \SORT_STRING);

        return $indexed;
    }
}
