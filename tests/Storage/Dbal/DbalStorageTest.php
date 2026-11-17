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
    public function testKeysAreCutOnCharactersNotBytes(Connection $connection): void
    {
        $this->reset($connection);
        $fits = 'GET ' . str_repeat('é', 251);
        $tooLong = 'GET ' . str_repeat('ü', 300);

        $this->storage($connection)->store([
            $this->aggregate(Period::OneHour, 1700000040, $fits, 1, 1.0, 1.0),
            $this->aggregate(Period::OneHour, 1700000040, $tooLong, 1, 1.0, 1.0),
        ]);

        $keys = $connection->fetchFirstColumn('SELECT metric_key FROM jmonitor_aggregates ORDER BY metric_key');
        static::assertSame([$fits, mb_substr($tooLong, 0, 255)], $keys);
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

    #[DataProvider('connections')]
    public function testNeverWritesInsideAnOpenTransaction(Connection $connection): void
    {
        $this->reset($connection);
        $connection->executeStatement('DROP TABLE IF EXISTS app_rows');
        $connection->executeStatement('CREATE TABLE app_rows (id INTEGER NOT NULL PRIMARY KEY)');
        $storage = $this->storage($connection);

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO app_rows (id) VALUES (1)');

        $refusals = 0;
        foreach ([
            fn() => $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)]),
            fn() => $storage->purge(1700700000),
        ] as $write) {
            try {
                $write();
            } catch (\RuntimeException) {
                ++$refusals;
            }
        }

        static::assertSame(2, $refusals);
        static::assertSame(1, $connection->getTransactionNestingLevel());
        $connection->rollBack();
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_rows'));
        static::assertFalse($connection->createSchemaManager()->tablesExist([AggregatesTable::NAME]));
        $connection->executeStatement('DROP TABLE app_rows');
    }

    #[DataProvider('connections')]
    public function testPrimaryKeyStartsWithPeriodThenBucket(Connection $connection): void
    {
        $sql = implode("\n", $connection->getDatabasePlatform()->getCreateTableSQL(AggregatesTable::definition()));

        static::assertSame(1, preg_match('/PRIMARY KEY\s*\(([^)]*)\)/i', $sql, $matches));
        static::assertSame('period,bucket,metric_type,key_hash', str_replace([' ', '"', '`'], '', $matches[1]));
    }

    public function testRowsAreSentInPrimaryKeyOrder(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $aggregates = [];
        foreach ([Period::SevenDays, Period::OneHour, Period::OneDay] as $period) {
            foreach ([1700000100, 1700000040] as $bucket) {
                foreach ([Metric::REQUEST_SLOW, Metric::REQUEST_DURATION, Metric::REQUEST_MEMORY] as $type) {
                    for ($i = 70; $i > 0; --$i) {
                        $aggregates[] = new Aggregate($period, $bucket, $type, 'GET route_' . $i, 1, 1.0, 1.0);
                    }
                }
            }
        }
        mt_srand(42);
        shuffle($aggregates);

        $this->storage($connection)->store($aggregates);

        $sent = [];
        foreach ($recorder->paramsOf('INSERT INTO ' . AggregatesTable::NAME) as $params) {
            ksort($params);
            foreach (array_chunk(array_values($params), 8) as $row) {
                $sent[] = [(int) $row[0], (int) $row[2], $row[1], $row[3]];
            }
        }
        $expected = $sent;
        usort($expected, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: strcmp($a[2], $b[2]) ?: strcmp($a[3], $b[3]));

        static::assertCount(\count($aggregates), $sent);
        static::assertGreaterThan(1, \count($recorder->paramsOf('INSERT INTO ' . AggregatesTable::NAME)));
        static::assertSame($expected, $sent);
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
