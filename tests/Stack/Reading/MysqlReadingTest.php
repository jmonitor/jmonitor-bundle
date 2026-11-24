<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Reading\MysqlReading;
use Jmonitor\JmonitorBundle\Stack\Reading\MysqlSlowQuery;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;

final class MysqlReadingTest extends TestCase
{
    private const PREVIOUS = [
        'Questions' => '1000',
        'Innodb_data_reads' => '500',
        'Innodb_data_writes' => '200',
        'Innodb_buffer_pool_read_requests' => '100000',
        'Innodb_buffer_pool_reads' => '1000',
        'Created_tmp_disk_tables' => '0',
        'Created_tmp_tables' => '50',
        'Threads_created' => '5',
        'Connections' => '1000',
        'Table_locks_waited' => '2',
        'Table_locks_immediate' => '998',
    ];

    public function testValuesOfACompleteSnapshot(): void
    {
        $reading = MysqlReading::of(self::snapshot(delta: Snapshots::delta(self::PREVIOUS, DatabasePayloads::mysqlStatus(), 10)), self::today());

        self::assertSame('MySQL', $reading->product);
        self::assertSame('8.4.0', $reading->version);
        self::assertSame('MySQL Community Server - GPL', $reading->versionComment);
        self::assertSame(SupportStatus::Active, $reading->support);
        self::assertSame(86_400, $reading->uptime);
        self::assertSame('SYSTEM', $reading->timeZone);
        self::assertSame('UTC', $reading->systemTimeZone);

        self::assertSame(30, $reading->connections);
        self::assertSame(100, $reading->maxConnections);
        self::assertSame(30.0, $reading->connectionsPercent);
        self::assertSame(45, $reading->maxUsedConnections);
        self::assertSame(3, $reading->running);
        self::assertSame(15.0, $reading->queriesPerSecond->value, '150 questions in 10 seconds');
        self::assertSame('q/s', $reading->queriesPerSecond->unit);

        self::assertSame(99.0, $reading->bufferPoolHitRate->value, '10 000 requests, 100 read from the disk');
        self::assertSame(RateSource::Delta, $reading->bufferPoolHitRate->source);
        self::assertSame(134_217_728, $reading->bufferPoolSize);
        self::assertSame(87.5, $reading->bufferPoolUsedPercent, '7 168 pages used out of 8 192');
        self::assertSame(5.0, $reading->innodbReadsPerSecond->value);
        self::assertSame(2.0, $reading->innodbWritesPerSecond->value);

        self::assertSame(20.0, $reading->diskTemporaryTables->value, '20 on disk out of 100');
        self::assertSame(RateSource::Delta, $reading->diskTemporaryTables->source);
        self::assertSame('20 / 100', $reading->diskTemporaryTables->fraction());
        self::assertSame(1.0, $reading->tableLockWaits->value, '1 waited for 99 immediate');
        self::assertSame(100, $reading->tableLocks);

        self::assertSame(52_428_800, $reading->dataLength);
        self::assertSame(10_485_760, $reading->indexLength);
        self::assertTrue($reading->sizesCached);
        self::assertEquals([new MysqlSlowQuery('SELECT * FROM `order` WHERE `status` = ?', 120, 300, 1_200, 36_000)], $reading->slowQueries);
        self::assertTrue($reading->slowQueryLog);
        self::assertSame(1.0, $reading->longQueryTime);
        self::assertSame(12, $reading->slowQueriesSinceStart);

        self::assertSame('100', $reading->settings['max_connections'] ?? null);
        self::assertSame('utf8mb4', $reading->settings['character_set_server'] ?? null);
        self::assertArrayNotHasKey('version', $reading->settings, 'shown on its own');
        self::assertArrayNotHasKey('timestamp', $reading->settings, 'not a setting');
    }

    public function testAnEmptySnapshotGivesNulls(): void
    {
        $failed = Snapshots::of(Component::Mysql, [], [
            'mysql' => ResultState::Failed,
            'mysql.variables' => ResultState::Failed,
            'mysql.sizes' => ResultState::Failed,
            'mysql.slow_queries' => ResultState::Failed,
        ]);

        foreach ([new ComponentSnapshot(Component::Mysql, []), $failed] as $snapshot) {
            $reading = MysqlReading::of($snapshot, self::today());

            self::assertEmptyReading($reading);
            self::assertNull($reading->product);
            self::assertSame([], $reading->settings);
        }
    }

    public function testDataOfUnknownShapeGivesNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $reading = MysqlReading::of(Snapshots::of(Component::Mysql, [
                'mysql' => ['Uptime' => 'long', 'Threads_connected' => [], 'Questions' => INF, 'Innodb_buffer_pool_read_requests' => 'many', 'Innodb_buffer_pool_pages_total' => ['8192']],
                'mysql.variables' => ['version' => ['8.4.0'], 'version_comment' => false, 'max_connections' => 'lots', 'slow_query_log' => 'perhaps', 'timestamp' => 'now', 'time_zone' => []],
                'mysql.sizes' => ['data_length' => 'big', 'index_length' => [1]],
                'mysql.slow_queries' => ['queries' => [['text' => ['SELECT 1'], 'executions' => 1], 'SELECT 2', ['text' => 'SELECT 3', 'executions' => 'twice']]],
                'eol.mysql' => ['cycles' => ['8.4' => 'ended']],
            ], deltas: ['mysql' => Snapshots::delta(['Questions' => 'x'], ['Questions' => ['y']])]), self::today());
            $notAnArray = MysqlReading::of(Snapshots::of(Component::Mysql, [
                'mysql' => ['up'],
                'mysql.variables' => ['version' => 'unknown'],
                'mysql.slow_queries' => ['queries' => 'none'],
            ]), self::today());
        } finally {
            restore_error_handler();
        }

        self::assertEmptyReading($reading);
        self::assertEmptyReading($notAnArray);
        self::assertSame(['max_connections' => 'lots', 'slow_query_log' => 'perhaps'], $reading->settings, 'as the server gives them');
    }

    public function testMariadbIsToldByItsVersion(): void
    {
        $reading = MysqlReading::of(self::snapshot(
            ['version' => '11.4.10-MariaDB-ubu2404', 'version_comment' => 'mariadb.org binary distribution'],
            ['eol.mysql' => ['cycles' => ['11.4' => ['eol' => true, 'support' => false]]], 'eol.mariadb' => ['cycles' => ['11.4' => ['eol' => '2029-05-29', 'support' => '2029-05-29']]]],
        ), self::today());

        self::assertSame('MariaDB', $reading->product);
        self::assertSame('11.4.10', $reading->version);
        self::assertSame(SupportStatus::Active, $reading->support, 'from the cycles of MariaDB');
    }

    public function testMysqlTakesItsOwnSupportDates(): void
    {
        $reading = MysqlReading::of(self::snapshot(
            ['version' => '8.0.40'],
            ['eol.mysql' => ['cycles' => ['8.0' => ['eol' => '2026-04-30', 'support' => '2023-07-18']]], 'eol.mariadb' => ['cycles' => ['8.0' => ['eol' => false, 'support' => true]]]],
        ), self::today());

        self::assertSame('MySQL', $reading->product);
        self::assertSame('8.0.40', $reading->version);
        self::assertSame(SupportStatus::Ended, $reading->support);
    }

    public function testAPerconaServerHasNoSupportStatus(): void
    {
        $cycles = ['eol.mysql' => ['cycles' => ['8.0' => ['eol' => '2026-04-30', 'support' => '2023-07-18']]]];

        $percona = MysqlReading::of(self::snapshot(['version' => '8.0.36-28', 'version_comment' => 'Percona Server (GPL), Release 28, Revision 47601f19'], $cycles), self::today());
        $tidb = MysqlReading::of(self::snapshot(['version' => '8.0.11-TiDB-v7.5.1', 'version_comment' => 'TiDB Server (Apache License 2.0) Community Edition, MySQL 8.0 compatible'], $cycles), self::today());

        self::assertSame('8.0.36', $percona->version);
        self::assertNull($percona->support, 'its own release cycles');
        self::assertSame('8.0.11', $tidb->version);
        self::assertNull($tidb->support);

        $aurora = MysqlReading::of(self::snapshot(['version' => '8.0.mysql_aurora.3.05.2', 'version_comment' => 'MySQL Community Server (GPL)'], $cycles), self::today());
        $toldByItsComment = MysqlReading::of(self::snapshot(['version' => '8.0.39', 'version_comment' => 'Source distribution, Aurora MySQL'], $cycles), self::today());

        self::assertSame('8.0', $aurora->version);
        self::assertNull($aurora->support);
        self::assertSame('8.0.39', $toldByItsComment->version);
        self::assertNull($toldByItsComment->support);
    }

    public function testWithoutASecondReadingTheRatesWait(): void
    {
        $reading = MysqlReading::of(self::snapshot(), self::today());

        self::assertTrue($reading->queriesPerSecond->isPending());
        self::assertTrue($reading->innodbReadsPerSecond->isPending());
        self::assertTrue($reading->innodbWritesPerSecond->isPending());
        self::assertNull($reading->tableLocks);
        self::assertSame(99.0, $reading->bufferPoolHitRate->value);
        self::assertSame('since the server started', $reading->bufferPoolHitRate->hint());
        self::assertSame(13.33, $reading->diskTemporaryTables->value);
        self::assertSame('since the server started', $reading->diskTemporaryTables->hint());
        self::assertSame(0.27, $reading->tableLockWaits->value, '3 waited out of 1 100');
    }

    public function testASmallVariationGivesWayToTheRateSinceTheServerStarted(): void
    {
        $window = static fn(int $requests, int $tables): MysqlReading => MysqlReading::of(self::snapshot(delta: Snapshots::delta(
            ['Innodb_buffer_pool_read_requests' => (string) (110_000 - $requests), 'Innodb_buffer_pool_reads' => '1100', 'Created_tmp_tables' => (string) (150 - $tables), 'Created_tmp_disk_tables' => '20'],
            DatabasePayloads::mysqlStatus(),
        )), self::today());

        $enough = $window(1_000, 100);
        $tooFew = $window(999, 99);

        self::assertSame(RateSource::Delta, $enough->bufferPoolHitRate->source);
        self::assertSame(100.0, $enough->bufferPoolHitRate->value, 'no read from the disk over the window');
        self::assertSame(RateSource::Delta, $enough->diskTemporaryTables->source);
        self::assertSame(0.0, $enough->diskTemporaryTables->value, 'none on disk over the window');

        self::assertSame(RateSource::SinceStart, $tooFew->bufferPoolHitRate->source);
        self::assertSame(99.0, $tooFew->bufferPoolHitRate->value, '110 000 requests, 1 100 read from the disk');
        self::assertSame('since the server started', $tooFew->bufferPoolHitRate->hint());
        self::assertSame(RateSource::SinceStart, $tooFew->diskTemporaryTables->source);
        self::assertSame(13.33, $tooFew->diskTemporaryTables->value, '20 on disk out of 150');
        self::assertSame('since the server started', $tooFew->diskTemporaryTables->hint());
    }

    public function testTheSizesOfMysqlEightAreCached(): void
    {
        $cached = static fn(string $version): bool => MysqlReading::of(self::snapshot(['version' => $version]), self::today())->sizesCached;

        self::assertTrue($cached('8.4.0'));
        self::assertTrue($cached('8.0.40'));
        self::assertTrue($cached('9.1.0'));
        self::assertFalse($cached('5.7.44-log'));
        self::assertFalse($cached('11.4.10-MariaDB-ubu2404'));
        self::assertFalse($cached('10.6.21-MariaDB'));
        self::assertFalse(MysqlReading::of(new ComponentSnapshot(Component::Mysql, []), self::today())->sizesCached, 'unknown version');
    }

    public function testADatabaseTheUserCannotSeeHasNoSize(): void
    {
        $reading = MysqlReading::of(self::snapshot(extra: ['mysql.sizes' => []]), self::today());

        self::assertNull($reading->dataLength);
        self::assertNull($reading->indexLength);
    }

    private static function assertEmptyReading(MysqlReading $reading): void
    {
        self::assertNull($reading->version);
        self::assertNull($reading->versionComment);
        self::assertNull($reading->support);
        self::assertNull($reading->uptime);
        self::assertNull($reading->timeZone);
        self::assertNull($reading->connections);
        self::assertNull($reading->maxConnections);
        self::assertNull($reading->connectionsPercent);
        self::assertNull($reading->maxUsedConnections);
        self::assertNull($reading->running);
        self::assertTrue($reading->queriesPerSecond->isUnavailable());
        self::assertFalse($reading->queriesPerSecond->isPending(), 'no later sample would bring it');
        self::assertTrue($reading->bufferPoolHitRate->isUnavailable());
        self::assertNull($reading->bufferPoolSize);
        self::assertNull($reading->bufferPoolUsedPercent);
        self::assertTrue($reading->diskTemporaryTables->isUnavailable());
        self::assertTrue($reading->tableLockWaits->isUnavailable());
        self::assertNull($reading->tableLocks);
        self::assertNull($reading->dataLength);
        self::assertNull($reading->indexLength);
        self::assertFalse($reading->sizesCached);
        self::assertSame([], $reading->slowQueries);
        self::assertNull($reading->slowQueryLog);
        self::assertNull($reading->longQueryTime);
        self::assertNull($reading->slowQueriesSinceStart);
    }

    /**
     * @param array<string, mixed>                $variables replacing those of a MySQL 8.4
     * @param ?array<string, array<string, mixed>> $extra    other collectors, or replacing them; the cycles of MySQL when not given
     */
    private static function snapshot(array $variables = [], ?array $extra = null, ?Delta $delta = null): ComponentSnapshot
    {
        return Snapshots::of(
            Component::Mysql,
            ($extra ?? ['eol.mysql' => Snapshots::cycles()]) + [
                'mysql' => DatabasePayloads::mysqlStatus(),
                'mysql.variables' => array_replace(DatabasePayloads::mysqlVariables(), $variables),
                'mysql.sizes' => DatabasePayloads::mysqlSizes(),
                'mysql.slow_queries' => DatabasePayloads::mysqlSlowQueries(),
            ],
            deltas: $delta === null ? [] : ['mysql' => $delta],
        );
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . Snapshots::NOW);
    }
}
