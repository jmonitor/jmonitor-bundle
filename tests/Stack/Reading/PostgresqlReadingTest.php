<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Reading\MysqlSlowQuery;
use Jmonitor\JmonitorBundle\Stack\Reading\PostgresqlBlockedSession;
use Jmonitor\JmonitorBundle\Stack\Reading\PostgresqlReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;

final class PostgresqlReadingTest extends TestCase
{
    private const PREVIOUS = [
        'database_stats' => [
            'xact_commit' => 124_900,
            'xact_rollback' => 110,
            'blks_read' => 3_980,
            'blks_hit' => 395_020,
        ],
    ];

    private const CYCLES = ['cycles' => [
        '17' => ['eol' => '2029-11-08', 'support' => '2029-11-08'],
        '16' => ['eol' => '2028-11-09', 'support' => '2028-11-09'],
        '13' => ['eol' => '2025-11-13', 'support' => '2025-11-13'],
        '11' => ['eol' => '2023-11-09', 'support' => '2023-11-09'],
        '9.6' => ['eol' => '2021-11-11', 'support' => '2021-11-11'],
    ]];

    public function testValuesOfACompleteSnapshot(): void
    {
        $reading = PostgresqlReading::of(self::snapshot(delta: Snapshots::delta(self::PREVIOUS, DatabasePayloads::postgresqlActivity(), 10)), self::today());

        self::assertSame('17.2', $reading->version);
        self::assertSame(SupportStatus::Active, $reading->support);

        self::assertSame(37, $reading->serverConnections);
        self::assertSame(100, $reading->maxConnections);
        self::assertSame(37.0, $reading->connectionsPercent, 'the connections of the whole server');
        self::assertSame(7, $reading->databaseConnections);
        self::assertSame(['active' => 2, 'idle' => 4, 'idle in transaction' => 1], $reading->connectionsByState);
        self::assertFalse($reading->limitedRole);
        self::assertSame(0, $reading->hiddenSessions);

        self::assertSame(98.0, $reading->cacheHitRate->value, '980 hits out of 1 000 blocks');
        self::assertSame(RateSource::Delta, $reading->cacheHitRate->source);
        self::assertSame(1_000, $reading->cacheBlocks);
        self::assertSame(11.0, $reading->transactionsPerSecond->value, '100 commits and 10 rollbacks in 10 seconds');
        self::assertSame('tx/s', $reading->transactionsPerSecond->unit);
        self::assertSame(9.09, $reading->rollbackRate->value, '10 rolled back out of 110');
        self::assertSame(RateSource::Delta, $reading->rollbackRate->source);
        self::assertSame(110, $reading->transactions);

        self::assertSame(4_000, $reading->deadRows);
        self::assertSame(250_000, $reading->liveRows);
        self::assertSame(4_000 / 254_000 * 100, $reading->deadRowsPercent);
        self::assertSame('public', $reading->schema);
        self::assertSame(12, $reading->tables);
        self::assertSame(83_886_080, $reading->databaseSize);
        self::assertSame(62_914_560, $reading->tablesSize);
        self::assertSame(20_971_520, $reading->indexesSize);

        self::assertSame(1, $reading->idleInTransaction);
        self::assertSame(340, $reading->oldestIdleInTransaction);
        self::assertSame(340, $reading->oldestTransaction);
        self::assertSame(1, $reading->blockedSessions);
        self::assertSame(45, $reading->longestBlock);
        self::assertEquals([new PostgresqlBlockedSession(4242, 45, 4100, 'idle in transaction')], $reading->blocked);

        self::assertTrue($reading->autovacuum);
        self::assertTrue($reading->trackCounts);
        self::assertSame('100', $reading->settings['max_connections'] ?? null);
        self::assertSame(134_217_728, $reading->settings['shared_buffers'] ?? null);
        self::assertArrayNotHasKey('server_version', $reading->settings, 'shown on its own');

        self::assertEquals([new MysqlSlowQuery('SELECT * FROM customer WHERE email = $1', 120, 300, 1_200, 36_000)], $reading->slowQueries);
        self::assertSame(0, $reading->hiddenStatements);
    }

    public function testAnEmptySnapshotGivesNulls(): void
    {
        $failed = Snapshots::of(Component::Postgresql, [], [
            'postgresql' => ResultState::Failed,
            'postgresql.settings' => ResultState::Failed,
            'postgresql.database' => ResultState::Failed,
            'postgresql.slow_queries' => ResultState::Failed,
        ]);

        foreach ([new ComponentSnapshot(Component::Postgresql, []), $failed] as $snapshot) {
            $reading = PostgresqlReading::of($snapshot, self::today());

            self::assertEmptyReading($reading);
            self::assertSame([], $reading->settings);
        }
    }

    public function testDataOfUnknownShapeGivesNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $reading = PostgresqlReading::of(Snapshots::of(Component::Postgresql, [
                'postgresql' => [
                    'database_stats' => ['numbackends' => 'many', 'blks_hit' => [1], 'blks_read' => INF, 'xact_commit' => 'lots', 'xact_rollback' => null],
                    'connections' => ['active' => 'two', 'unknown' => []],
                    'sessions' => [
                        'idle_in_transaction_count' => [],
                        'oldest_idle_in_transaction_seconds' => 'long',
                        'blocked_count' => 'one',
                        'blocked_queries' => ['x', ['blocked_pid' => 'a', 'wait_seconds' => 50], ['wait_seconds' => 50]],
                    ],
                    'server_connections' => [37],
                ],
                'postgresql.settings' => ['server_version' => ['17.2'], 'max_connections' => 'lots', 'autovacuum' => 'maybe', 'work_mem' => [1]],
                'postgresql.database' => ['schema' => [], 'db_size' => 'big', 'tables' => 'none'],
                'postgresql.slow_queries' => ['queries' => [['text' => ['SELECT 1'], 'executions' => 1], 'SELECT 2', ['text' => 'SELECT 3', 'executions' => 'twice']], 'hidden' => 'some'],
                'eol.postgresql' => ['cycles' => ['17' => 'ended']],
            ], deltas: ['postgresql' => Snapshots::delta(['database_stats' => 'x'], ['database_stats' => ['blks_hit' => ['y']]])]), self::today());
            $notAnArray = PostgresqlReading::of(Snapshots::of(Component::Postgresql, [
                'postgresql' => ['database_stats' => 'none', 'connections' => 'none', 'sessions' => 'none'],
                'postgresql.settings' => ['server_version' => 'unknown'],
                'postgresql.slow_queries' => ['queries' => 'none'],
            ]), self::today());
        } finally {
            restore_error_handler();
        }

        self::assertEmptyReading($reading);
        self::assertEmptyReading($notAnArray);
        self::assertSame(['max_connections' => 'lots', 'autovacuum' => 'maybe'], $reading->settings, 'as the server gives them');
    }

    public function testTheVersionIsTheNumbersAheadOfTheBuild(): void
    {
        $version = static fn(string $version): PostgresqlReading => PostgresqlReading::of(self::snapshot(['server_version' => $version]), self::today());

        self::assertSame('16.4', $version('16.4 (Debian 16.4-1.pgdg120+1)')->version);
        self::assertSame(SupportStatus::Active, $version('16.4 (Debian 16.4-1.pgdg120+1)')->support);
        self::assertSame('17.2', $version('17.2')->version);
        self::assertSame('9.6.24', $version('9.6.24')->version);
        self::assertSame(SupportStatus::Ended, $version('9.6.24')->support, 'three numbers before 10');
        self::assertSame(SupportStatus::Ended, $version('13.18')->support);
        self::assertSame('17', $version('17devel')->version);
        self::assertNull($version('17devel')->support, 'a development version has no cycle');
    }

    public function testAForkHasNoSupportStatus(): void
    {
        $cockroach = PostgresqlReading::of(self::snapshot(['server_version' => '13.0.0']), self::today());
        $yugabyte = PostgresqlReading::of(self::snapshot(['server_version' => '11.2-YB-2.20']), self::today());

        self::assertSame('13.0.0', $cockroach->version);
        self::assertNull($cockroach->support, 'three numbers from 10 on are not PostgreSQL');
        self::assertSame('11.2', $yugabyte->version);
        self::assertNull($yugabyte->support);
    }

    public function testWithoutASecondReadingTheRatesWait(): void
    {
        $reading = PostgresqlReading::of(self::snapshot(), self::today());

        self::assertTrue($reading->transactionsPerSecond->isPending());
        self::assertNull($reading->transactions);
        self::assertSame(99.0, $reading->cacheHitRate->value, '396 000 hits out of 400 000 blocks');
        self::assertSame('since the statistics were reset', $reading->cacheHitRate->hint());
        self::assertSame(400_000, $reading->cacheBlocks);
        self::assertSame(0.1, $reading->rollbackRate->value, '120 rolled back out of 125 120');
        self::assertSame('since the statistics were reset', $reading->rollbackRate->hint());
    }

    public function testTheCacheHitRateIsTheVariationWhenItCountsEnough(): void
    {
        $window = static fn(int $blocks): PostgresqlReading => PostgresqlReading::of(self::snapshot(delta: Snapshots::delta(
            ['database_stats' => ['blks_hit' => 396_000 - $blocks, 'blks_read' => 4_000]],
            DatabasePayloads::postgresqlActivity(),
        )), self::today());

        $enough = $window(1_000);
        $tooFew = $window(999);

        self::assertSame(RateSource::Delta, $enough->cacheHitRate->source);
        self::assertSame(100.0, $enough->cacheHitRate->value, 'no block read outside the cache over the window');
        self::assertSame(1_000, $enough->cacheBlocks);

        self::assertSame(RateSource::SinceStart, $tooFew->cacheHitRate->source);
        self::assertSame(99.0, $tooFew->cacheHitRate->value);
        self::assertSame('since the statistics were reset', $tooFew->cacheHitRate->hint());
        self::assertSame(400_000, $tooFew->cacheBlocks, 'the blocks of the rate shown');
    }

    public function testARoleThatSeesNoStateIsLimited(): void
    {
        $limited = PostgresqlReading::of(self::snapshot(activity: ['connections' => ['active' => 1, 'unknown' => 4]]), self::today());
        $seesAll = PostgresqlReading::of(self::snapshot(activity: ['connections' => ['active' => 1, 'unknown' => 0]]), self::today());

        self::assertTrue($limited->limitedRole);
        self::assertSame(4, $limited->hiddenSessions);
        self::assertSame(['active' => 1], $limited->connectionsByState);
        self::assertFalse($seesAll->limitedRole);
        self::assertSame(0, $seesAll->hiddenSessions);
    }

    public function testABlockedSessionCarriesNoQueryText(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['sessions']['blocked_queries'][0] += [
            'blocked_query_sample' => "UPDATE account SET password = 's3cret' WHERE id = 7",
            'blocking_query_sample' => "UPDATE account SET email = 'jane@example.com' WHERE id = 7",
        ];
        $reading = PostgresqlReading::of(self::snapshot(activity: $activity), self::today());

        self::assertSame(
            ['pid', 'waitSeconds', 'blockingPid', 'blockingState'],
            array_map(static fn(\ReflectionProperty $property): string => $property->getName(), (new \ReflectionClass(PostgresqlBlockedSession::class))->getProperties()),
        );
        self::assertCount(1, $reading->blocked);
        self::assertStringNotContainsString('s3cret', print_r($reading, true));
        self::assertStringNotContainsString('jane@example.com', print_r($reading, true));
    }

    public function testAnUnknownWaitIsNotTheLongest(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['sessions']['max_wait_seconds'] = 0;
        $activity['sessions']['blocked_queries'] = [
            ['blocked_pid' => 4242, 'wait_seconds' => null, 'blocking_pid' => 4100, 'blocking_state' => null],
            ['blocked_pid' => 4243, 'wait_seconds' => null, 'blocking_pid' => 4100, 'blocking_state' => null],
        ];
        $unknown = PostgresqlReading::of(self::snapshot(activity: $activity), self::today());
        $activity['sessions']['blocked_queries'][1]['wait_seconds'] = 12;
        $oneKnown = PostgresqlReading::of(self::snapshot(activity: $activity), self::today());

        self::assertNull($unknown->longestBlock, 'the library gives 0 when it knows no wait');
        self::assertEquals([new PostgresqlBlockedSession(4242, null, 4100, null), new PostgresqlBlockedSession(4243, null, 4100, null)], $unknown->blocked);
        self::assertSame(12, $oneKnown->longestBlock);
    }

    public function testAnUnknownCountOfTheServerConnectionsGivesNoShare(): void
    {
        $reading = PostgresqlReading::of(self::snapshot(activity: ['server_connections' => null]), self::today());

        self::assertNull($reading->serverConnections);
        self::assertNull($reading->connectionsPercent);
        self::assertSame(100, $reading->maxConnections);
        self::assertSame(7, $reading->databaseConnections);
    }

    public function testTheHiddenStatementsAreCounted(): void
    {
        $reading = PostgresqlReading::of(self::snapshot(extra: ['postgresql.slow_queries' => ['queries' => [], 'hidden' => 3]]), self::today());

        self::assertSame([], $reading->slowQueries);
        self::assertSame(3, $reading->hiddenStatements);
    }

    private static function assertEmptyReading(PostgresqlReading $reading): void
    {
        self::assertNull($reading->version);
        self::assertNull($reading->support);
        self::assertNull($reading->serverConnections);
        self::assertNull($reading->maxConnections);
        self::assertNull($reading->connectionsPercent);
        self::assertNull($reading->databaseConnections);
        self::assertSame([], $reading->connectionsByState);
        self::assertFalse($reading->limitedRole);
        self::assertNull($reading->hiddenSessions);
        self::assertTrue($reading->cacheHitRate->isUnavailable());
        self::assertNull($reading->cacheBlocks);
        self::assertTrue($reading->transactionsPerSecond->isUnavailable());
        self::assertFalse($reading->transactionsPerSecond->isPending(), 'no later sample would bring it');
        self::assertTrue($reading->rollbackRate->isUnavailable());
        self::assertNull($reading->transactions);
        self::assertNull($reading->deadRows);
        self::assertNull($reading->liveRows);
        self::assertNull($reading->deadRowsPercent);
        self::assertNull($reading->schema);
        self::assertNull($reading->tables);
        self::assertNull($reading->databaseSize);
        self::assertNull($reading->tablesSize);
        self::assertNull($reading->indexesSize);
        self::assertNull($reading->idleInTransaction);
        self::assertNull($reading->oldestIdleInTransaction);
        self::assertNull($reading->oldestTransaction);
        self::assertNull($reading->blockedSessions);
        self::assertNull($reading->longestBlock);
        self::assertSame([], $reading->blocked);
        self::assertNull($reading->autovacuum);
        self::assertNull($reading->trackCounts);
        self::assertSame([], $reading->slowQueries);
        self::assertNull($reading->hiddenStatements);
    }

    /**
     * @param array<string, mixed>                $settings replacing those of a PostgreSQL 17
     * @param array<string, mixed>                $activity replacing the activity of the fixture, key by key
     * @param array<string, array<string, mixed>> $extra    other collectors, or replacing them
     */
    private static function snapshot(array $settings = [], array $activity = [], array $extra = [], ?Delta $delta = null): ComponentSnapshot
    {
        return Snapshots::of(
            Component::Postgresql,
            $extra + [
                'postgresql' => array_replace(DatabasePayloads::postgresqlActivity(), $activity),
                'postgresql.settings' => array_replace(DatabasePayloads::postgresqlSettings(), $settings),
                'postgresql.database' => DatabasePayloads::postgresqlDatabase(),
                'postgresql.slow_queries' => DatabasePayloads::postgresqlSlowQueries(),
                'eol.postgresql' => self::CYCLES,
            ],
            deltas: $delta === null ? [] : ['postgresql' => $delta],
        );
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . Snapshots::NOW);
    }
}
