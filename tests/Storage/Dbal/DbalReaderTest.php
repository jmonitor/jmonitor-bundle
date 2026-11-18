<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
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

        $view = (new RequestsScreen())->build(new DbalReader($connection), Period::OneHour, self::NOW, 'total', 'desc');

        static::assertSame(['4', '405 ms', '1.5 s', '25 %'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        $home = $view->tables[0]->rows[1];
        static::assertSame('GET app_home', $home->attributes['data-jm-route']);
        static::assertSame(
            ['route' => 'app_home', 'requests' => '3', 'total' => '120 ms', 'avg' => '40 ms', 'p95' => '60 ms', 'max' => '60 ms', 'sql' => '—', 'errors' => '1', 'slow' => '0', 'memory' => '2.9 KB'],
            array_map(static fn(Cell $cell): string => $cell->text, $home->cells),
        );
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
