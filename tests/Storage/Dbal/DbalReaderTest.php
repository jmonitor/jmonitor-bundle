<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Storage\Read\Totals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DbalReaderTest extends TestCase
{
    private const NOW = 1700003600;

    /** @return array<string, array{Connection}> */
    public static function connections(): array
    {
        return ConnectionProvider::all();
    }

    #[DataProvider('connections')]
    public function testRoutesAreAssembledFromAggregates(Connection $connection): void
    {
        $this->seed($connection, $this->currentEntries());

        $routes = $this->indexByKey($this->read($connection)->rows);

        static::assertSame(['GET app_home', 'POST app_cart_add'], array_keys($routes));
        $home = $routes['GET app_home'];
        static::assertSame(3, $home->count(Metric::REQUEST_DURATION));
        static::assertSame(120.0, $home->sum(Metric::REQUEST_DURATION));
        static::assertSame(60.0, $home->max(Metric::REQUEST_DURATION));
        static::assertSame(1, $home->count(Metric::REQUEST_STATUS_PREFIX . '5xx'));
        static::assertSame(0, $home->count(Metric::REQUEST_SLOW));
        static::assertSame(3000.0, $home->max(Metric::REQUEST_MEMORY));
        static::assertSame(3, array_sum($home->countsByPrefix(Metric::REQUEST_DURATION_LE_PREFIX)));
        static::assertSame(1, $routes['POST app_cart_add']->count(Metric::REQUEST_SLOW));
    }

    #[DataProvider('connections')]
    public function testTheRequestsScreenDerivesItsValuesFromTheReport(Connection $connection): void
    {
        $this->seed($connection, $this->currentEntries());

        $checks = new ApplicationChecks([new ServerErrorsCheck(), new SlowRequestsCheck(1000), new SlowQueriesCheck(100), new RepeatedQueriesCheck(10)]);
        $view = (new RequestsScreen($checks, 1000))->build(new DbalReader($connection), Period::OneHour, self::NOW, 'total', 'desc');

        static::assertSame(['405 ms', '1.5 s', '1.5 s', '25 %', '1'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        $home = $view->tables[0]->rows[1];
        static::assertSame('GET app_home', $home->attributes['data-jm-route']);
        static::assertSame(
            ['route' => 'app_home', 'requests' => '3', 'total' => '120 ms', 'avg' => '40 ms', 'p95' => '60 ms', 'max' => '60 ms', 'split' => 'PHP only', 'problems' => '', 'memory' => '2.9 KB'],
            array_map(static fn(Cell $cell): string => $cell->text, $home->cells),
        );
    }

    #[DataProvider('connections')]
    public function testThePageSortedByProblemsStartsWithTheCulpritOfTheSlowCheck(Connection $connection): void
    {
        $queries = [
            'a1a1a1a1a1a1a1a1' => ['SELECT * FROM orders WHERE customer = ?', 3, 400.0],
            'b2b2b2b2b2b2b2b2' => ['SELECT * FROM invoices WHERE customer = ?', 3, 300.0],
            'c3c3c3c3c3c3c3c3' => ['SELECT * FROM items WHERE id = ?', 1, 200.0],
            'd4d4d4d4d4d4d4d4' => ['SELECT 1', 0, 1.0],
        ];
        $children = [];
        foreach ($queries as $id => [, $slow, $max]) {
            $children[] = new Entry(Entry::TYPE_QUERY, $id, (float) (self::NOW - 10), $max, ['n' => 4, 'm' => $max, 'sl' => $slow]);
        }
        ConnectionProvider::dropTables($connection);
        (new DbalStorage($connection, 604800))->store(
            (new Aggregator(1000))->aggregate([new Entry(Entry::TYPE_REQUEST, 'GET app_list', (float) (self::NOW - 10), 900.0, ['status' => 200, 'memory' => 1024], $children)]),
            self::NOW,
            array_combine(array_keys($queries), array_column($queries, 0)),
        );
        $reader = new DbalReader($connection);
        $check = new SlowQueriesCheck(100);

        $page = $reader->read(new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_SLOW, 'count', true, 100, Metric::QUERY_DURATION));
        $culprit = $reader->read($check->query(Period::OneHour, self::NOW));

        static::assertSame([3, 3], [$page->rows[0]->count(Metric::QUERY_SLOW), $page->rows[1]->count(Metric::QUERY_SLOW)], 'Two queries share the most slow executions.');
        static::assertSame($culprit->rows[0]->key, $page->rows[0]->key);

        $screen = new QueriesScreen(true, new ApplicationChecks([$check]), 100, 10);
        $codes = [];
        foreach ([['problems', 'desc'], ['problems', 'asc'], ['total', 'desc']] as [$sort, $direction]) {
            $view = $screen->build($reader, Period::OneHour, self::NOW, $sort, $direction);
            static::assertNotNull($view->checks);
            $codes[] = $view->checks->lines[0]->code;
        }
        static::assertSame(array_fill(0, 3, $culprit->rows[0]->label), $codes);
    }

    #[DataProvider('connections')]
    public function testEntriesOutsideTheWindowAreExcluded(Connection $connection): void
    {
        $this->seed($connection, [$this->request('GET app_old', self::NOW - 7200, 30.0, 200, 1000)]);

        static::assertSame([], $this->read($connection)->rows);
        static::assertCount(1, $this->read($connection, Period::SixHours)->rows);
    }

    #[DataProvider('connections')]
    public function testRequestSeriesHasSixtyBuckets(Connection $connection): void
    {
        $this->seed($connection, $this->currentEntries());

        $series = $this->read($connection)->series;

        static::assertCount(60, $series);
        static::assertSame(4, array_sum($series));
        static::assertSame(4, $series[59]);
    }

    #[DataProvider('connections')]
    public function testLongKeysSharingATruncatedPrefixStaySeparate(Connection $connection): void
    {
        $long = 'GET ' . str_repeat('a', 400);
        $this->seed($connection, [
            $this->request($long . 'x', self::NOW - 10, 5.0, 200, 100),
            $this->request($long . 'y', self::NOW - 10, 5.0, 200, 100),
        ]);

        static::assertSame([$long . 'x', $long . 'y'], array_keys($this->indexByKey($this->read($connection)->rows)));
    }

    public function testTheRequestFamilyIsReadInOneGroupedQuery(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->seed($connection, $this->currentEntries());
        $grouped = static fn(): int => \count($recorder->paramsOf('SELECT key_hash, metric_type'));

        static::assertCount(2, $this->read($connection)->rows);
        static::assertSame(1, $grouped());

        static::assertCount(2, $this->read($connection)->rows);
        static::assertSame(2, $grouped());
    }

    #[DataProvider('connections')]
    public function testTotalsSumEveryKeyOfEachAskedType(Connection $connection): void
    {
        $bucket = Period::OneHour->bucketFor(self::NOW);
        $this->store($connection, [
            new Aggregate(Period::OneHour, $bucket, Metric::REQUEST_DURATION, 'GET app_home', 3, 120.0, 60.0),
            new Aggregate(Period::OneHour, $bucket - 60, Metric::REQUEST_DURATION, 'GET app_home', 1, 15.0, 15.0),
            new Aggregate(Period::OneHour, $bucket, Metric::REQUEST_DURATION, 'POST app_cart_add', 1, 1500.0, 1500.0),
            new Aggregate(Period::OneHour, $bucket, Metric::REQUEST_DURATION, Aggregator::OTHER, 2, 30.0, 20.0),
            new Aggregate(Period::OneHour, $bucket, Metric::REQUEST_SLOW, 'POST app_cart_add', 1, 1500.0, 1500.0),
            new Aggregate(Period::OneHour, $bucket, Metric::REQUEST_SLOW, Aggregator::OTHER, 1, 900.0, 900.0),
            new Aggregate(Period::OneHour, $bucket, Metric::REQUEST_MEMORY, 'GET app_home', 3, 6000.0, 3000.0),
        ]);

        $totals = $this->totals($connection, [Metric::REQUEST_DURATION, Metric::REQUEST_SLOW])->types;

        ksort($totals, \SORT_STRING);
        static::assertSame(
            [Metric::REQUEST_DURATION => [7, 1665.0, 1500.0], Metric::REQUEST_SLOW => [2, 2400.0, 1500.0]],
            array_map(static fn(MetricValue $value): array => [$value->count, $value->sum, $value->max], $totals),
        );
    }

    #[DataProvider('connections')]
    public function testTotalsLeaveOutATypeWithoutData(Connection $connection): void
    {
        $this->store($connection, [new Aggregate(Period::OneHour, Period::OneHour->bucketFor(self::NOW), Metric::REQUEST_DURATION, 'GET app_home', 1, 20.0, 20.0)]);

        static::assertSame([Metric::REQUEST_DURATION], array_keys($this->totals($connection, [Metric::QUERY_SLOW, Metric::REQUEST_DURATION, 'unknown.type'])->types));
    }

    #[DataProvider('connections')]
    public function testTotalsOnlyCountTheWindowOfThePeriod(Connection $connection): void
    {
        $period = Period::SevenDays;
        $lastBucketBefore = $period->bucketFor(self::NOW) - $period->value;
        $this->store($connection, [
            new Aggregate($period, $lastBucketBefore, Metric::REQUEST_DURATION, 'GET app_home', 1, 1.0, 1.0),
            new Aggregate($period, $lastBucketBefore + $period->bucketSeconds(), Metric::REQUEST_DURATION, 'GET app_home', 2, 20.0, 10.0),
            new Aggregate(Period::OneDay, Period::OneDay->bucketFor(self::NOW), Metric::REQUEST_DURATION, 'GET app_home', 4, 400.0, 100.0),
        ]);

        $total = $this->totals($connection, [Metric::REQUEST_DURATION], period: $period)->types[Metric::REQUEST_DURATION] ?? null;

        static::assertNotNull($total);
        static::assertSame([2, 20.0, 10.0], [$total->count, $total->sum, $total->max]);
    }

    #[DataProvider('connections')]
    public function testTotalsIgnoreATypeAskedTwice(Connection $connection): void
    {
        $this->store($connection, [new Aggregate(Period::OneHour, Period::OneHour->bucketFor(self::NOW), Metric::REQUEST_SLOW, 'GET app_home', 1, 600.0, 600.0)]);

        $total = $this->totals($connection, [Metric::REQUEST_SLOW, Metric::REQUEST_SLOW])->types[Metric::REQUEST_SLOW] ?? null;

        static::assertNotNull($total);
        static::assertSame([1, 600.0, 600.0], [$total->count, $total->sum, $total->max]);
    }

    #[DataProvider('connections')]
    public function testTotalsGiveTheKeysOfTheKeyedTypes(Connection $connection): void
    {
        $bucket = Period::OneHour->bucketFor(self::NOW);
        $this->store($connection, [
            new Aggregate(Period::OneHour, $bucket, Metric::CACHE_HIT, 'cache.app', 8, 0.0, 0.0),
            new Aggregate(Period::OneHour, $bucket - 60, Metric::CACHE_HIT, 'cache.app', 2, 0.0, 0.0),
            new Aggregate(Period::OneHour, $bucket - 3600, Metric::CACHE_HIT, 'cache.app', 50, 0.0, 0.0),
            new Aggregate(Period::OneHour, $bucket, Metric::CACHE_HIT, 'cache.users', 3, 0.0, 0.0),
            new Aggregate(Period::OneHour, $bucket, Metric::CACHE_MISS, 'cache.users', 9, 0.0, 0.0),
            new Aggregate(Period::OneHour, $bucket, Metric::REQUEST_SLOW, 'GET app_home', 1, 900.0, 900.0),
            new Aggregate(Period::OneHour, $bucket, Metric::REQUEST_SLOW, 'GET app_list', 2, 1300.0, 700.0),
        ]);
        $app = AggregatesTable::keyHash('cache.app');
        $users = AggregatesTable::keyHash('cache.users');

        $mixed = $this->totals($connection, [Metric::REQUEST_SLOW, Metric::CACHE_HIT, Metric::CACHE_MISS], [Metric::CACHE_HIT, Metric::CACHE_MISS]);

        static::assertSame(
            [Metric::CACHE_HIT => [13, 0.0, 0.0], Metric::CACHE_MISS => [9, 0.0, 0.0], Metric::REQUEST_SLOW => [3, 2200.0, 900.0]],
            self::flat($mixed->types),
        );
        static::assertSame(
            [Metric::CACHE_HIT => self::byKey([$app => [10, 0.0, 0.0], $users => [3, 0.0, 0.0]]), Metric::CACHE_MISS => [$users => [9, 0.0, 0.0]]],
            self::flatKeys($mixed->keys),
        );

        $keyedOnly = $this->totals($connection, [Metric::CACHE_HIT, Metric::CACHE_MISS], [Metric::CACHE_MISS, Metric::CACHE_HIT]);

        static::assertSame([Metric::CACHE_HIT => [13, 0.0, 0.0], Metric::CACHE_MISS => [9, 0.0, 0.0]], self::flat($keyedOnly->types));
        static::assertSame(self::flatKeys($mixed->keys), self::flatKeys($keyedOnly->keys));
    }

    #[DataProvider('connections')]
    public function testTheKeyedTotalsLeaveTheOtherKeyOutButCountItInTheType(Connection $connection): void
    {
        $bucket = Period::OneHour->bucketFor(self::NOW);
        $this->store($connection, [
            new Aggregate(Period::OneHour, $bucket, Metric::HTTP_DURATION, 'api.example.com', 4, 400.0, 150.0),
            new Aggregate(Period::OneHour, $bucket - 60, Metric::HTTP_DURATION, 'api.example.com', 1, 50.0, 50.0),
            new Aggregate(Period::OneHour, $bucket, Metric::HTTP_DURATION, Aggregator::OTHER, 2, 900.0, 800.0),
            new Aggregate(Period::OneHour, $bucket, Metric::HTTP_FAILED, Aggregator::OTHER, 1, 0.0, 0.0),
        ]);

        $totals = $this->totals($connection, [Metric::HTTP_DURATION, Metric::HTTP_FAILED], [Metric::HTTP_DURATION, Metric::HTTP_FAILED]);

        static::assertSame([Metric::HTTP_DURATION => [7, 1350.0, 800.0], Metric::HTTP_FAILED => [1, 0.0, 0.0]], self::flat($totals->types));
        static::assertSame([Metric::HTTP_DURATION => [AggregatesTable::keyHash('api.example.com') => [5, 450.0, 150.0]]], self::flatKeys($totals->keys));
    }

    #[DataProvider('connections')]
    public function testAKeyedTypeWithoutDataIsLeftOut(Connection $connection): void
    {
        $bucket = Period::OneHour->bucketFor(self::NOW);
        $this->store($connection, [
            new Aggregate(Period::OneHour, $bucket, Metric::CACHE_HIT, 'cache.app', 5, 0.0, 0.0),
            new Aggregate(Period::OneHour, $bucket, Metric::REQUEST_SLOW, 'GET app_home', 1, 900.0, 900.0),
            new Aggregate(Period::OneDay, Period::OneDay->bucketFor(self::NOW), Metric::CACHE_MISS, 'cache.app', 5, 0.0, 0.0),
        ]);

        $totals = $this->totals($connection, [Metric::REQUEST_SLOW, Metric::QUERY_SLOW, Metric::CACHE_HIT, Metric::CACHE_MISS], [Metric::CACHE_HIT, Metric::CACHE_MISS]);

        static::assertSame([Metric::CACHE_HIT, Metric::REQUEST_SLOW], self::sortedKeys($totals->types));
        static::assertSame([Metric::CACHE_HIT], array_keys($totals->keys));

        $nothing = $this->totals($connection, [Metric::REQUEST_SLOW, Metric::CACHE_MISS], [Metric::CACHE_MISS]);

        static::assertSame([Metric::REQUEST_SLOW], array_keys($nothing->types));
        static::assertSame([], $nothing->keys);
    }

    #[DataProvider('connections')]
    public function testTotalsIgnoreAKeyedTypeAskedTwice(Connection $connection): void
    {
        $this->store($connection, [new Aggregate(Period::OneHour, Period::OneHour->bucketFor(self::NOW), Metric::CACHE_HIT, 'cache.app', 5, 0.0, 0.0)]);

        $totals = $this->totals($connection, [Metric::CACHE_HIT, Metric::REQUEST_SLOW, Metric::CACHE_HIT], [Metric::CACHE_HIT, Metric::CACHE_HIT]);

        static::assertSame([Metric::CACHE_HIT => [5, 0.0, 0.0]], self::flat($totals->types));
        static::assertSame([Metric::CACHE_HIT => [AggregatesTable::keyHash('cache.app') => [5, 0.0, 0.0]]], self::flatKeys($totals->keys));
    }

    public function testAKeyedTypeThatIsNotAskedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DbalReader(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])))->totals(Period::OneHour, self::NOW, [Metric::CACHE_HIT], [Metric::CACHE_MISS]);
    }

    /** @return array<string, array{Connection, StatementRecorder}> */
    public static function recordedConnections(): array
    {
        return ConnectionProvider::recorded();
    }

    #[DataProvider('recordedConnections')]
    public function testTotalsReadInOneStatementWhateverTheNumberOfTypes(Connection $connection, StatementRecorder $recorder): void
    {
        $this->store($connection, [new Aggregate(Period::OneHour, Period::OneHour->bucketFor(self::NOW), Metric::REQUEST_DURATION, 'GET app_home', 1, 20.0, 20.0)]);
        $recorder->statements = [];

        $this->totals($connection, [Metric::REQUEST_DURATION]);
        static::assertCount(1, $recorder->statements);

        $this->totals($connection, [Metric::REQUEST_STATUS_PREFIX . '5xx', Metric::REQUEST_SLOW, Metric::QUERY_SLOW, Metric::NPLUS1_CALLS]);
        static::assertCount(2, $recorder->statements);
    }

    #[DataProvider('recordedConnections')]
    public function testTotalsReadInOneStatementWithKeyedTypes(Connection $connection, StatementRecorder $recorder): void
    {
        $this->store($connection, [new Aggregate(Period::OneHour, Period::OneHour->bucketFor(self::NOW), Metric::CACHE_HIT, 'cache.app', 5, 0.0, 0.0)]);
        $recorder->statements = [];

        $this->totals($connection, [Metric::REQUEST_SLOW, Metric::QUERY_SLOW, Metric::CACHE_HIT, Metric::CACHE_MISS], [Metric::CACHE_HIT, Metric::CACHE_MISS]);
        static::assertCount(1, $recorder->statements);
        static::assertStringContainsString(' UNION ALL ', $recorder->statements[0]['sql']);

        $this->totals($connection, [Metric::CACHE_HIT, Metric::CACHE_MISS], [Metric::CACHE_HIT, Metric::CACHE_MISS]);
        static::assertCount(2, $recorder->statements);
        static::assertStringNotContainsString('UNION', $recorder->statements[1]['sql']);
    }

    #[DataProvider('recordedConnections')]
    public function testTotalsWithoutKeyedTypeRunTheSameStatementAsBefore(Connection $connection, StatementRecorder $recorder): void
    {
        $this->store($connection, [new Aggregate(Period::OneHour, Period::OneHour->bucketFor(self::NOW), Metric::REQUEST_SLOW, 'GET app_home', 1, 900.0, 900.0)]);
        $recorder->statements = [];

        $this->totals($connection, [Metric::REQUEST_SLOW, Metric::QUERY_SLOW]);
        $this->totals($connection, [Metric::REQUEST_SLOW, Metric::QUERY_SLOW], []);

        $expected = 'SELECT metric_type, SUM(entry_count) AS entry_count, SUM(value_sum) AS value_sum, MAX(value_max) AS value_max'
            . ' FROM jmonitor_aggregates WHERE period = ? AND bucket > ? AND metric_type IN (?, ?) GROUP BY metric_type';
        static::assertSame([$expected, $expected], array_column($recorder->statements, 'sql'));
        static::assertSame(
            [3600, Period::OneHour->bucketFor(self::NOW) - 3600, Metric::REQUEST_SLOW, Metric::QUERY_SLOW],
            array_values($recorder->statements[0]['params']),
        );
    }

    #[DataProvider('connections')]
    public function testTotalsWithoutTheTableAreEmpty(Connection $connection): void
    {
        ConnectionProvider::dropTables($connection);

        static::assertEquals(new Totals(), $this->totals($connection, [Metric::REQUEST_DURATION]));
        static::assertEquals(new Totals(), $this->totals($connection, [Metric::REQUEST_DURATION, Metric::CACHE_HIT], [Metric::CACHE_HIT]));
    }

    public function testTotalsOfNoTypeAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DbalReader(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])))->totals(Period::OneHour, self::NOW, []);
    }

    /**
     * @param non-empty-list<string> $metricTypes
     * @param list<string>           $keyed
     */
    private function totals(Connection $connection, array $metricTypes, array $keyed = [], Period $period = Period::OneHour): Totals
    {
        return (new DbalReader($connection))->totals($period, self::NOW, $metricTypes, $keyed);
    }

    /**
     * @param array<string, MetricValue> $values
     *
     * @return array<string, array{int, float, float}> sorted by metric type
     */
    private static function flat(array $values): array
    {
        ksort($values, \SORT_STRING);

        return array_map(static fn(MetricValue $value): array => [$value->count, $value->sum, $value->max], $values);
    }

    /**
     * @param array<string, array<string, MetricValue>> $keys
     *
     * @return array<string, array<string, array{int, float, float}>> sorted by metric type and key hash
     */
    private static function flatKeys(array $keys): array
    {
        ksort($keys, \SORT_STRING);

        return array_map(self::flat(...), $keys);
    }

    /**
     * @template T
     *
     * @param array<string, T> $values
     *
     * @return array<string, T>
     */
    private static function byKey(array $values): array
    {
        ksort($values, \SORT_STRING);

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private static function sortedKeys(array $values): array
    {
        $keys = array_map('strval', array_keys($values));
        sort($keys, \SORT_STRING);

        return $keys;
    }

    /** @param list<Aggregate> $aggregates */
    private function store(Connection $connection, array $aggregates): void
    {
        ConnectionProvider::dropTables($connection);
        (new DbalStorage($connection, 604800))->store($aggregates, self::NOW);
    }

    private function read(Connection $connection, Period $period = Period::OneHour): Report
    {
        return (new DbalReader($connection))->read(new ReportQuery($period, self::NOW, Family::Request, seriesMetric: Metric::REQUEST_DURATION));
    }

    /** @param list<Entry> $entries */
    private function seed(Connection $connection, array $entries): void
    {
        ConnectionProvider::dropTables($connection);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate($entries), self::NOW);
    }

    /** @return list<Entry> */
    private function currentEntries(): array
    {
        return [
            $this->request('GET app_home', self::NOW - 20, 20.0, 200, 1000),
            $this->request('GET app_home', self::NOW - 15, 40.0, 200, 3000),
            $this->request('GET app_home', self::NOW - 10, 60.0, 500, 2000),
            $this->request('POST app_cart_add', self::NOW - 5, 1500.0, 200, 500),
        ];
    }

    private function request(string $key, int $startedAt, float $durationMs, int $status, int $memory): Entry
    {
        return new Entry(Entry::TYPE_REQUEST, $key, (float) $startedAt, $durationMs, ['status' => $status, 'memory' => $memory]);
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
