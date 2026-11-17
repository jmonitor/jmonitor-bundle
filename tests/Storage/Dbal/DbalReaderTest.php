<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Read\RouteStats;
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

        $routes = $this->indexByKey((new DbalReader($connection))->routes(Period::OneHour, self::NOW));

        static::assertCount(2, $routes);
        $home = $routes['GET app_home'];
        static::assertSame('GET', $home->method);
        static::assertSame('app_home', $home->route);
        static::assertSame(3, $home->requests);
        static::assertSame(40.0, $home->avgMs);
        static::assertSame(60.0, $home->maxMs);
        static::assertSame(120.0, $home->totalMs);
        static::assertSame(1, $home->errors);
        static::assertSame(0, $home->slow);
        static::assertSame(3000, $home->peakMemoryBytes);
        static::assertSame(1, $routes['POST app_cart_add']->slow);
        static::assertLessThanOrEqual(60.0, $home->p95Ms);
    }

    #[DataProvider('connections')]
    public function testEntriesOutsideTheWindowAreExcluded(Connection $connection): void
    {
        $this->seed($connection, [$this->request('GET app_old', self::NOW - 7200, 30.0, 200, 1000)]);
        $reader = new DbalReader($connection);

        static::assertSame([], $reader->routes(Period::OneHour, self::NOW));
        static::assertCount(1, $reader->routes(Period::SixHours, self::NOW));
    }

    #[DataProvider('connections')]
    public function testSummary(Connection $connection): void
    {
        $this->seed($connection, $this->currentEntries());

        $summary = (new DbalReader($connection))->summary(Period::OneHour, self::NOW);

        static::assertSame(4, $summary->requests);
        static::assertSame(405.0, $summary->avgMs);
        static::assertSame(0.25, $summary->errorRate);
        static::assertGreaterThan(0.0, $summary->p95Ms);
        static::assertLessThanOrEqual(1500.0, $summary->p95Ms);
    }

    #[DataProvider('connections')]
    public function testRequestSeriesHasSixtyBuckets(Connection $connection): void
    {
        $this->seed($connection, $this->currentEntries());

        $series = (new DbalReader($connection))->requestSeries(Period::OneHour, self::NOW);

        static::assertCount(60, $series);
        static::assertSame(4, array_sum($series));
        static::assertSame(4, $series[59]);
    }

    #[DataProvider('connections')]
    public function testMissingTableGivesEmptyResults(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);
        $reader = new DbalReader($connection);

        static::assertSame([], $reader->routes(Period::OneHour, self::NOW));
        static::assertSame(0, $reader->summary(Period::OneHour, self::NOW)->requests);
        static::assertSame(0.0, $reader->summary(Period::OneHour, self::NOW)->p95Ms);
        static::assertSame(array_fill(0, 60, 0), $reader->requestSeries(Period::OneHour, self::NOW));
    }

    #[DataProvider('connections')]
    public function testRouteNameContainingSpaces(Connection $connection): void
    {
        $this->seed($connection, [$this->request('GET (unmatched)', self::NOW - 10, 5.0, 404, 100)]);

        $routes = (new DbalReader($connection))->routes(Period::OneHour, self::NOW);

        static::assertCount(1, $routes);
        static::assertSame('GET', $routes[0]->method);
        static::assertSame('(unmatched)', $routes[0]->route);
    }

    #[DataProvider('connections')]
    public function testLongKeysSharingATruncatedPrefixStaySeparate(Connection $connection): void
    {
        $long = 'GET ' . str_repeat('a', 400);
        $this->seed($connection, [
            $this->request($long . 'x', self::NOW - 10, 5.0, 200, 100),
            $this->request($long . 'y', self::NOW - 10, 5.0, 200, 100),
        ]);

        static::assertCount(2, (new DbalReader($connection))->routes(Period::OneHour, self::NOW));
    }

    /** @param list<Entry> $entries */
    private function seed(Connection $connection, array $entries): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate($entries));
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
     * @param list<RouteStats> $routes
     *
     * @return array<string, RouteStats>
     */
    private function indexByKey(array $routes): array
    {
        $indexed = [];
        foreach ($routes as $route) {
            $indexed[$route->key] = $route;
        }

        return $indexed;
    }
}
