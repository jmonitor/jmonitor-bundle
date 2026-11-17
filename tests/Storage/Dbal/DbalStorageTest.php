<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DbalStorageTest extends TestCase
{
    /** @return array<string, array{Connection}> */
    public static function connections(): array
    {
        return ConnectionProvider::all();
    }

    #[DataProvider('connections')]
    public function testTableIsCreatedOnFirstStore(Connection $connection): void
    {
        $this->reset($connection);

        $this->storage($connection)->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 2, 100.0, 80.0)]);

        static::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
    }

    #[DataProvider('connections')]
    public function testStoreIsAdditive(Connection $connection): void
    {
        $this->reset($connection);
        $storage = $this->storage($connection);
        $a = new Aggregate(Period::OneHour, 1700000040, Metric::REQUEST_DURATION, 'GET app_home', 2, 100.0, 80.0);
        $b = new Aggregate(Period::OneHour, 1700000040, Metric::REQUEST_DURATION, 'GET app_home', 3, 60.0, 30.0);

        $storage->store([$a]);
        $storage->store([$b]);

        $row = $connection->fetchAssociative('SELECT entry_count, value_sum, value_max FROM jmonitor_aggregates');
        static::assertSame(5, (int) $row['entry_count']);
        static::assertSame(160.0, (float) $row['value_sum']);
        static::assertSame(80.0, (float) $row['value_max']);
    }

    #[DataProvider('connections')]
    public function testMoreRowsThanOneChunk(Connection $connection): void
    {
        $this->reset($connection);
        $aggregates = [];
        for ($i = 0; $i < 1200; ++$i) {
            $aggregates[] = $this->aggregate(Period::OneHour, 1700000040, 'GET route_' . $i, 1, 1.0, 1.0);
        }

        $this->storage($connection)->store($aggregates);

        static::assertSame(1200, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
    }

    #[DataProvider('connections')]
    public function testEmptyStoreRunsNoQuery(Connection $connection): void
    {
        $this->reset($connection);

        $this->storage($connection)->store([]);

        static::assertFalse($connection->createSchemaManager()->tablesExist([AggregatesTable::NAME]));
    }

    #[DataProvider('connections')]
    public function testLongAndInvalidKeys(Connection $connection): void
    {
        $this->reset($connection);
        $long = str_repeat('a', 399);

        $this->storage($connection)->store([
            $this->aggregate(Period::OneHour, 1700000040, $long . 'x', 1, 1.0, 1.0),
            $this->aggregate(Period::OneHour, 1700000040, $long . 'y', 1, 1.0, 1.0),
            $this->aggregate(Period::OneHour, 1700000040, "GET caf\xE9", 1, 1.0, 1.0),
        ]);

        $rows = $connection->fetchAllAssociative('SELECT metric_key, key_hash FROM jmonitor_aggregates');
        static::assertCount(3, $rows);
        foreach ($rows as $row) {
            static::assertLessThanOrEqual(255, mb_strlen($row['metric_key']));
        }
        static::assertCount(3, array_unique(array_column($rows, 'key_hash')));
    }

    #[DataProvider('connections')]
    public function testPurgeKeepsEachPeriodWindow(Connection $connection): void
    {
        $this->reset($connection);
        $now = 1700700000;
        $storage = $this->storage($connection, 604800);
        $storage->store([
            $this->aggregate(Period::OneHour, $now - 7200, 'GET old_hour', 1, 1.0, 1.0),
            $this->aggregate(Period::SevenDays, $now - 7200, 'GET recent_week', 1, 1.0, 1.0),
            $this->aggregate(Period::SevenDays, $now - 8 * 86400, 'GET old_week', 1, 1.0, 1.0),
        ]);

        $storage->purge($now);

        static::assertSame(['GET recent_week'], $connection->fetchFirstColumn('SELECT metric_key FROM jmonitor_aggregates'));
    }

    #[DataProvider('connections')]
    public function testPurgeHonoursShorterRetention(Connection $connection): void
    {
        $this->reset($connection);
        $now = 1700700000;
        $storage = $this->storage($connection, 86400);
        $storage->store([$this->aggregate(Period::SevenDays, $now - 2 * 86400, 'GET old_week', 1, 1.0, 1.0)]);

        $storage->purge($now);

        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
    }

    #[DataProvider('connections')]
    public function testPurgeWithoutTableDoesNothing(Connection $connection): void
    {
        $this->reset($connection);

        $this->storage($connection)->purge(1700700000);

        static::assertFalse($connection->createSchemaManager()->tablesExist([AggregatesTable::NAME]));
    }

    private function reset(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);
    }

    private function storage(Connection $connection, int $retention = 604800): DbalStorage
    {
        return new DbalStorage($connection, $retention);
    }

    private function aggregate(Period $period, int $bucket, string $key, int $count, float $sum, float $max): Aggregate
    {
        return new Aggregate($period, $bucket, Metric::REQUEST_DURATION, $key, $count, $sum, $max);
    }
}
