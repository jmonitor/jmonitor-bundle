<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Dbal\KeysTable;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class DbalStorageTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->files);
        $this->files = [];
    }

    /** @return array<string, array{Connection}> */
    public static function connections(): array
    {
        return ConnectionProvider::all();
    }

    #[DataProvider('connections')]
    public function testTableIsCreatedOnFirstStore(Connection $connection): void
    {
        $this->reset($connection);

        $this->storage($connection)->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 2, 100.0, 80.0)], 1700000100);

        static::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
    }

    #[DataProvider('connections')]
    public function testStoreIsAdditive(Connection $connection): void
    {
        $this->reset($connection);
        $storage = $this->storage($connection);
        $a = new Aggregate(Period::OneHour, 1700000040, Metric::REQUEST_DURATION, 'GET app_home', 2, 100.0, 80.0);
        $b = new Aggregate(Period::OneHour, 1700000040, Metric::REQUEST_DURATION, 'GET app_home', 3, 60.0, 30.0);

        $storage->store([$a], 1700000100);
        $storage->store([$b], 1700000100);

        $row = $connection->fetchAssociative('SELECT entry_count, value_sum, value_max FROM jmonitor_aggregates');
        static::assertSame(5, (int) $row['entry_count']);
        static::assertSame(160.0, (float) $row['value_sum']);
        static::assertSame(80.0, (float) $row['value_max']);
    }

    #[DataProvider('connections')]
    public function testTableDroppedWhileTheProcessRunsIsCreatedAgain(Connection $connection): void
    {
        $this->reset($connection);
        $storage = $this->storage($connection);
        $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)], 1700000100);

        $this->reset($connection);
        $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 2, 1.0, 1.0)], 1700000100);

        static::assertSame(2, (int) $connection->fetchOne('SELECT SUM(entry_count) FROM jmonitor_aggregates'));
    }

    #[DataProvider('connections')]
    public function testMoreRowsThanOneChunk(Connection $connection): void
    {
        $this->reset($connection);
        $aggregates = [];
        for ($i = 0; $i < 1200; ++$i) {
            $aggregates[] = $this->aggregate(Period::OneHour, 1700000040, 'GET route_' . $i, 1, 1.0, 1.0);
        }

        $this->storage($connection)->store($aggregates, 1700000100);

        static::assertSame(1200, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
    }

    #[DataProvider('connections')]
    public function testEmptyStoreRunsNoQuery(Connection $connection): void
    {
        $this->reset($connection);

        $this->storage($connection)->store([], 1700000100);

        static::assertFalse($connection->createSchemaManager()->tablesExist([AggregatesTable::NAME]));
        static::assertFalse($connection->createSchemaManager()->tablesExist([KeysTable::NAME]));
    }

    #[DataProvider('connections')]
    public function testLongAndInvalidKeys(Connection $connection): void
    {
        $this->reset($connection);
        $long = str_repeat('a', KeysTable::LABEL_MAX_LENGTH);

        $this->storage($connection)->store([
            $this->aggregate(Period::OneHour, 1700000040, $long . 'x', 1, 1.0, 1.0),
            $this->aggregate(Period::OneHour, 1700000040, $long . 'y', 1, 1.0, 1.0),
            $this->aggregate(Period::OneHour, 1700000040, "GET caf\xE9", 1, 1.0, 1.0),
        ], 1700000100);

        static::assertCount(3, $connection->fetchFirstColumn('SELECT key_hash FROM jmonitor_aggregates'));
        $labels = $this->labels($connection);
        static::assertCount(3, $labels);
        foreach ($labels as $label) {
            static::assertLessThanOrEqual(KeysTable::LABEL_MAX_LENGTH, mb_strlen($label));
            static::assertTrue(mb_check_encoding($label, 'UTF-8'));
        }
    }

    #[DataProvider('connections')]
    public function testKeysAreCutOnCharactersNotBytes(Connection $connection): void
    {
        $this->reset($connection);
        $fits = 'GET ' . str_repeat('é', KeysTable::LABEL_MAX_LENGTH - 4);
        $tooLong = 'GET ' . str_repeat('ü', KeysTable::LABEL_MAX_LENGTH);

        $this->storage($connection)->store([
            $this->aggregate(Period::OneHour, 1700000040, $fits, 1, 1.0, 1.0),
            $this->aggregate(Period::OneHour, 1700000040, $tooLong, 1, 1.0, 1.0),
        ], 1700000100);

        $labels = $this->labels($connection);
        sort($labels);
        static::assertSame([$fits, mb_substr($tooLong, 0, KeysTable::LABEL_MAX_LENGTH)], $labels);
    }

    #[DataProvider('connections')]
    public function testKeysKeepFourByteCharacters(Connection $connection): void
    {
        $this->reset($connection);
        $key = "GET app_\u{1F680}_launch";

        $this->storage($connection)->store([$this->aggregate(Period::OneHour, 1700000040, $key, 1, 1.0, 1.0)], 1700000100);

        static::assertSame([$key], $this->labels($connection));
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
        ], $now);

        $storage->purge($now);

        static::assertSame(
            [AggregatesTable::keyHash('GET recent_week')],
            $connection->fetchFirstColumn('SELECT key_hash FROM jmonitor_aggregates'),
        );
    }

    #[DataProvider('connections')]
    public function testPurgeHonoursShorterRetention(Connection $connection): void
    {
        $this->reset($connection);
        $now = 1700700000;
        $storage = $this->storage($connection, 86400);
        $storage->store([$this->aggregate(Period::SevenDays, $now - 2 * 86400, 'GET old_week', 1, 1.0, 1.0)], $now);

        $storage->purge($now);

        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
    }

    #[DataProvider('connections')]
    public function testPurgeWithoutTableDoesNothing(Connection $connection): void
    {
        $this->reset($connection);

        $this->storage($connection)->purge(1700700000);

        static::assertFalse($connection->createSchemaManager()->tablesExist([AggregatesTable::NAME]));
        static::assertFalse($connection->createSchemaManager()->tablesExist([KeysTable::NAME]));
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
            fn() => $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)], 1700000100),
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
        static::assertFalse($connection->createSchemaManager()->tablesExist([KeysTable::NAME]));
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

        $this->storage($connection)->store($aggregates, 1700000100);

        $sent = [];
        foreach ($recorder->paramsOf('INSERT INTO ' . AggregatesTable::NAME) as $params) {
            ksort($params);
            foreach (array_chunk(array_values($params), 7) as $row) {
                $sent[] = [(int) $row[0], (int) $row[2], $row[1], $row[3]];
            }
        }
        $expected = $sent;
        usort($expected, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: strcmp($a[2], $b[2]) ?: strcmp($a[3], $b[3]));

        static::assertCount(\count($aggregates), $sent);
        static::assertGreaterThan(1, \count($recorder->paramsOf('INSERT INTO ' . AggregatesTable::NAME)));
        static::assertSame($expected, $sent);

        $hashes = [];
        foreach ($recorder->paramsOf('INSERT INTO ' . KeysTable::NAME) as $params) {
            ksort($params);
            foreach (array_chunk(array_values($params), 3) as $row) {
                if ($row[0] !== KeysTable::VERSION_ROW) {
                    $hashes[] = $row[0];
                }
            }
        }
        $sorted = $hashes;
        sort($sorted, \SORT_STRING);
        static::assertCount(70, $hashes);
        static::assertSame($sorted, $hashes);
    }

    public function testKeyRefreshesAndInsertsFollowOneHashOrder(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = $this->storage($connection);
        $aggregates = [];
        $labels = [];
        for ($i = 0; $i < 300; ++$i) {
            $aggregates[] = $this->aggregate(Period::OneHour, 1700000040, 'k' . $i, 1, 1.0, 1.0);
            $labels['k' . $i] = $i % 3 === 0 ? 'label ' . $i : null;
        }
        $storage->store(array_values(array_filter($aggregates, static fn(Aggregate $a): bool => $labels[$a->key] === null)), 1000);
        $recorder->statements = [];

        $storage->store($aggregates, 2000, $labels);

        $hashes = [];
        foreach ($recorder->statements as ['sql' => $sql, 'params' => $params]) {
            ksort($params);
            $params = array_values($params);
            if (str_starts_with($sql, 'UPDATE ' . KeysTable::NAME)) {
                array_push($hashes, ...\array_slice($params, 1));
            } elseif (str_starts_with($sql, 'INSERT INTO ' . KeysTable::NAME)) {
                foreach (array_chunk($params, 3) as $row) {
                    $hashes[] = $row[0];
                }
            }
        }
        $sorted = $hashes;
        sort($sorted, \SORT_STRING);
        static::assertSame($sorted, $hashes);
        static::assertCount(300, $hashes);
        static::assertSame(300, (int) $connection->fetchOne("SELECT COUNT(*) FROM jmonitor_keys WHERE last_seen = 2000"));
        static::assertSame('label 3', $connection->fetchOne('SELECT metric_key FROM jmonitor_keys WHERE key_hash = ?', [AggregatesTable::keyHash('k3')]));
        static::assertSame('k4', $connection->fetchOne('SELECT metric_key FROM jmonitor_keys WHERE key_hash = ?', [AggregatesTable::keyHash('k4')]));
    }

    public function testSqliteStatementsStayWithinTheOldParameterLimit(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $aggregates = [];
        for ($i = 0; $i < 250; ++$i) {
            $aggregates[] = $this->aggregate(Period::OneHour, 1700000040, 'GET route_' . $i, 1, 1.0, 1.0);
        }

        $this->storage($connection)->store($aggregates, 1700000100);

        $inserts = $recorder->paramsOf('INSERT INTO ' . AggregatesTable::NAME);
        static::assertSame(250, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . AggregatesTable::NAME));
        static::assertCount(3, $inserts);
        foreach ([...$inserts, ...$recorder->paramsOf('INSERT INTO ' . KeysTable::NAME)] as $params) {
            static::assertLessThanOrEqual(999, \count($params));
        }
    }

    public function testUnsupportedPlatformCreatesNoTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => SqlServerReportingConnection::class]);
        $storage = $this->storage($connection);

        foreach ([
            static fn() => $storage->prepare(),
            fn() => $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)], 1700000100),
        ] as $operation) {
            try {
                $operation();
                static::fail('An unsupported platform must be refused.');
            } catch (\LogicException $e) {
                static::assertStringContainsString('Unsupported database platform', $e->getMessage());
            }
        }

        static::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'jmonitor_%'"));
    }

    public function testDriverWithoutNativeConnectionIsTreatedAsHavingNoTransaction(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => NoNativeConnection::class]);
        $storage = $this->storage($connection);

        $storage->prepare();
        $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)], 1700000100);

        static::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
    }

    #[DataProvider('connections')]
    public function testATableOfTheFirstSchemaIsReplaced(Connection $connection): void
    {
        $this->reset($connection);
        $this->createFirstSchema($connection);

        $this->storage($connection)->prepare();

        $columns = array_map(
            static fn(string $name): string => strtolower(trim($name, '`"')),
            array_keys($connection->createSchemaManager()->listTableColumns(AggregatesTable::NAME)),
        );
        static::assertNotContains('metric_key', $columns);
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
        static::assertSame('2', $connection->fetchOne("SELECT metric_key FROM jmonitor_keys WHERE key_hash = 'schema'"));
    }

    #[DataProvider('connections')]
    public function testTablesOfANewerSchemaAreLeftAlone(Connection $connection): void
    {
        $this->reset($connection);
        $this->storage($connection)->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)], 1700000100);
        $connection->executeStatement("UPDATE jmonitor_keys SET metric_key = '3' WHERE key_hash = 'schema'");

        try {
            $this->storage($connection)->prepare();
            static::fail('Tables of a newer schema must be refused.');
        } catch (\RuntimeException $e) {
            static::assertStringContainsString('newer version', $e->getMessage());
        }

        static::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
        static::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_keys'));
        static::assertSame('3', $connection->fetchOne("SELECT metric_key FROM jmonitor_keys WHERE key_hash = 'schema'"));
    }

    #[DataProvider('connections')]
    public function testAKeyIsWrittenOnceAndItsLastSightRefreshed(Connection $connection): void
    {
        $this->reset($connection);
        $storage = $this->storage($connection);
        $a = $this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0);

        $storage->store([$a], 1000);
        $storage->store([$a], 2000);

        $rows = $connection->fetchAllAssociative(
            'SELECT metric_key, last_seen FROM jmonitor_keys WHERE key_hash = ?',
            [AggregatesTable::keyHash('GET app_home')],
        );
        static::assertCount(1, $rows);
        static::assertSame('GET app_home', $rows[0]['metric_key']);
        static::assertSame(2000, (int) $rows[0]['last_seen']);
    }

    #[DataProvider('connections')]
    public function testLabelsOverrideTheKey(Connection $connection): void
    {
        $this->reset($connection);
        $storage = $this->storage($connection);
        $query = new Aggregate(Period::OneHour, 1700000040, 'query.duration', 'a1b2c3d4e5f6a7b8', 1, 1.0, 1.0);
        $other = new Aggregate(Period::OneHour, 1700000040, 'query.duration', 'ffffffffffffffff', 1, 1.0, 1.0);
        $row = static fn(string $key): array|false => $connection->fetchAssociative(
            'SELECT metric_key, last_seen FROM jmonitor_keys WHERE key_hash = ?',
            [AggregatesTable::keyHash($key)],
        );

        $storage->store([$query], 1000, ['a1b2c3d4e5f6a7b8' => 'SELECT 1']);
        static::assertSame('SELECT 1', $row('a1b2c3d4e5f6a7b8')['metric_key'] ?? null);

        $storage->store([$query], 2000, ['a1b2c3d4e5f6a7b8' => null]);
        $refreshed = $row('a1b2c3d4e5f6a7b8');
        static::assertSame('SELECT 1', $refreshed['metric_key'] ?? null);
        static::assertSame(2000, (int) ($refreshed['last_seen'] ?? 0));

        $storage->store([$other], 2000, ['ffffffffffffffff' => null]);
        static::assertFalse($row('ffffffffffffffff'));
    }

    #[DataProvider('connections')]
    public function testALabelWithoutAggregateIsWrittenOnlyWithItsText(Connection $connection): void
    {
        $this->reset($connection);
        $repeated = new Aggregate(Period::OneHour, 1700000040, Metric::NPLUS1_DURATION, 'request' . Entry::KEY_SEPARATOR . 'GET app_list' . Entry::KEY_SEPARATOR . 'a1b2c3d4e5f6a7b8', 1, 1.0, 1.0);

        $this->storage($connection)->store([$repeated], 1000, ['a1b2c3d4e5f6a7b8' => 'SELECT 1', 'ffffffffffffffff' => null]);

        static::assertSame('SELECT 1', $connection->fetchOne('SELECT metric_key FROM jmonitor_keys WHERE key_hash = ?', [AggregatesTable::keyHash('a1b2c3d4e5f6a7b8')]));
        static::assertFalse($connection->fetchOne('SELECT metric_key FROM jmonitor_keys WHERE key_hash = ?', [AggregatesTable::keyHash('ffffffffffffffff')]));
        static::assertCount(2, $this->labels($connection));
    }

    #[DataProvider('connections')]
    public function testPurgeDropsKeysNotSeenForTheRetentionPlusADay(Connection $connection): void
    {
        $this->reset($connection);
        $storage = $this->storage($connection, 3600);
        $storage->store([$this->aggregate(Period::OneHour, 960, 'GET app_home', 1, 1.0, 1.0)], 1000);
        $hash = AggregatesTable::keyHash('GET app_home');
        $kept = static fn(): bool => $connection->fetchOne('SELECT 1 FROM jmonitor_keys WHERE key_hash = ?', [$hash]) !== false;

        $storage->purge(1000 + 3600 + 86400 - 1);
        static::assertTrue($kept());

        $storage->purge(1000 + 3600 + 86400 + 1);
        static::assertFalse($kept());
        static::assertSame('2', $connection->fetchOne("SELECT metric_key FROM jmonitor_keys WHERE key_hash = 'schema'"));
    }

    public function testAnUpgradeWithoutTheRightToDropTablesFailsCleanly(): void
    {
        $dir = sys_get_temp_dir() . '/jmonitor-ddl-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $params = ['driver' => 'pdo_sqlite', 'path' => $dir . '/app.sqlite'];

        try {
            $this->createFirstSchema(DriverManager::getConnection($params));
            $connection = DdlDenier::connect($params);
            $storage = $this->storage($connection);

            try {
                $storage->prepare();
                static::fail('An upgrade without the right to drop tables must fail.');
            } catch (\RuntimeException $e) {
                static::assertStringContainsString('Permission denied', $e->getMessage());
            }

            static::assertSame('GET app_home', $connection->fetchOne('SELECT metric_key FROM jmonitor_aggregates'));
            static::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name = 'jmonitor_keys'"));

            $clock = new MockClock('@1000');
            $directory = new BufferDirectory($dir, 'app');
            $buffer = new FileBuffer($directory, 10, $clock);
            $buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1000.0, 20.0, ['status' => 200]));
            $clock->sleep(11);
            $flusher = new Flusher($buffer, new Aggregator(500), $storage, new FlushLock($directory, 10, $clock), $clock);

            static::assertFalse($flusher->flush());
            static::assertNotNull($flusher->lastFailure());
            static::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    #[DataProvider('connections')]
    public function testAggregatesTableDroppedUnderACurrentVersionIsCreatedAgain(Connection $connection): void
    {
        $this->reset($connection);
        $storage = $this->storage($connection);
        $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)], 1700000100);

        $connection->executeStatement('DROP TABLE ' . AggregatesTable::NAME);
        $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 2, 1.0, 1.0)], 1700000100);

        static::assertSame(2, (int) $connection->fetchOne('SELECT SUM(entry_count) FROM jmonitor_aggregates'));
        static::assertSame('2', $connection->fetchOne("SELECT metric_key FROM jmonitor_keys WHERE key_hash = 'schema'"));
    }

    public function testAnOlderVersionIsNotMistakenForAConcurrentInstall(): void
    {
        $params = $this->sqliteFile();
        $this->storage(DriverManager::getConnection($params))->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)], 1700000100);
        $connection = DdlDenier::connect($params);
        $connection->executeStatement("UPDATE jmonitor_keys SET metric_key = '1' WHERE key_hash = 'schema'");
        $storage = $this->storage($connection);

        foreach ([static fn() => $storage->prepare(), static fn() => $storage->setUp()] as $operation) {
            try {
                $operation();
                static::fail('An upgrade without the right to drop tables must fail.');
            } catch (\RuntimeException $e) {
                static::assertStringContainsString('Permission denied', $e->getMessage());
            }
        }

        static::assertSame('1', $connection->fetchOne("SELECT metric_key FROM jmonitor_keys WHERE key_hash = 'schema'"));
        static::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
    }

    public function testAnInstallFinishedMeanwhileByAnotherServerIsAccepted(): void
    {
        $params = $this->sqliteFile();
        $other = DriverManager::getConnection($params);
        $connection = DdlDenier::connect($params, fn() => $this->storage($other)->prepare());
        $storage = $this->storage($connection);

        $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)], 1700000100);

        static::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
        static::assertSame('2', $connection->fetchOne("SELECT metric_key FROM jmonitor_keys WHERE key_hash = 'schema'"));
    }

    public function testTablesCreatedByAnotherServerBeforeItsVersionRowAreAccepted(): void
    {
        $params = $this->sqliteFile();
        $other = DriverManager::getConnection($params);
        $connection = DdlDenier::connect($params, static function () use ($other): void {
            foreach ([AggregatesTable::definition(), KeysTable::definition()] as $table) {
                foreach ($other->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
                    $other->executeStatement($sql);
                }
            }
        });
        $storage = $this->storage($connection);

        $storage->store([$this->aggregate(Period::OneHour, 1700000040, 'GET app_home', 1, 1.0, 1.0)], 1700000100);

        static::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM jmonitor_aggregates'));
    }

    /** @return array{driver: 'pdo_sqlite', path: string} */
    private function sqliteFile(): array
    {
        $path = sys_get_temp_dir() . '/jmonitor-storage-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->files[] = $path;

        return ['driver' => 'pdo_sqlite', 'path' => $path];
    }

    private function createFirstSchema(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE jmonitor_aggregates (period INTEGER NOT NULL, metric_type VARCHAR(64) NOT NULL,'
            . ' bucket BIGINT NOT NULL, key_hash CHAR(32) NOT NULL, metric_key VARCHAR(255) NOT NULL,'
            . ' entry_count BIGINT NOT NULL, value_sum DOUBLE PRECISION NOT NULL, value_max DOUBLE PRECISION NOT NULL,'
            . ' PRIMARY KEY (period, bucket, metric_type, key_hash))',
        );
        $connection->insert(AggregatesTable::NAME, [
            'period' => 3600,
            'metric_type' => Metric::REQUEST_DURATION,
            'bucket' => 1700000040,
            'key_hash' => AggregatesTable::keyHash('GET app_home'),
            'metric_key' => 'GET app_home',
            'entry_count' => 1,
            'value_sum' => 1.0,
            'value_max' => 1.0,
        ]);
    }

    /** @return list<string> */
    private function labels(Connection $connection): array
    {
        return array_map('strval', $connection->fetchFirstColumn("SELECT metric_key FROM jmonitor_keys WHERE key_hash <> 'schema'"));
    }

    private function reset(Connection $connection): void
    {
        ConnectionProvider::dropTables($connection);
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

final class SqlServerReportingConnection extends Connection
{
    public function getDatabasePlatform(): AbstractPlatform
    {
        return new SQLServerPlatform();
    }
}

final class NoNativeConnection extends Connection
{
    public function getNativeConnection(): mixed
    {
        throw new \LogicException('The driver does not expose its native connection.');
    }
}
