<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionException;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlSlowQueriesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use PHPUnit\Framework\TestCase;

final class MysqlSlowQueriesCollectorTest extends TestCase
{
    private const ENABLED = ['@@performance_schema' => [['enabled' => '1']]];

    public function testItKeepsTheSlowestQueries(): void
    {
        $database = self::database([DatabasePayloads::digest('SELECT * FROM `order` WHERE `status` = ?', 120, 300, 1200, 36000)]);
        $collector = new MysqlSlowQueriesCollector(new DbalLink(static fn() => $database), 'shop');

        self::assertSame(DatabasePayloads::mysqlSlowQueries(), $collector->collect());
        self::assertSame('mysql.slow_queries', $collector->getName());
        self::assertSame(1, $collector->getVersion());
        self::assertSame('SELECT @@performance_schema AS enabled', $database->queries[0]);
        self::assertStringContainsString('COUNT_STAR >= 1', $database->queries[2]);
        self::assertStringContainsString('AVG_TIMER_WAIT DESC', $database->queries[2]);
        self::assertStringContainsString('LIMIT 20', $database->queries[2]);
    }

    public function testWithoutPerformanceSchemaItIsAMissingPrerequisite(): void
    {
        $database = new FakeDatabase(['@@performance_schema' => [['enabled' => '0']]]);

        $failure = Failures::thrownBy(static fn() => (new MysqlSlowQueriesCollector(new DbalLink(static fn() => $database), 'shop'))->collect());

        self::assertInstanceOf(MissingPrerequisiteException::class, $failure);
        self::assertSame('performance_schema is off on this server, so its slowest queries cannot be read.', $failure->getMessage());
        self::assertSame(1, $database->calls());
    }

    public function testItsOwnQueriesAreLeftOut(): void
    {
        $database = self::database([
            DatabasePayloads::digest('SELECT SUM ( `DATA_LENGTH` ) AS `data_length` , SUM ( `INDEX_LENGTH` ) AS `index_length` FROM `information_schema` . `TABLES` WHERE `TABLE_SCHEMA` = ?', 3, 900),
            DatabasePayloads::digest('SELECT LEFT ( `DIGEST_TEXT` , ? ) AS `query_sample` , `COUNT_STAR` AS `exec_count` FROM `performance_schema` . `events_statements_summary_by_digest` WHERE `SCHEMA_NAME` = ?', 3, 800),
            DatabasePayloads::digest('SELECT * FROM `product` WHERE `id` = ?', 40, 12),
        ]);

        $queries = (new MysqlSlowQueriesCollector(new DbalLink(static fn() => $database), 'shop'))->collect()['queries'];

        self::assertSame(['SELECT * FROM `product` WHERE `id` = ?'], array_column($queries, 'text'));
    }

    public function testOnlyTenAreKept(): void
    {
        $rows = [];
        for ($i = 20; $i > 0; --$i) {
            $rows[] = DatabasePayloads::digest('SELECT * FROM `t' . $i . '` WHERE `id` = ?', 2, $i);
        }

        $queries = (new MysqlSlowQueriesCollector(new DbalLink(static fn() => self::database($rows)), 'shop'))->collect()['queries'];

        self::assertCount(10, $queries);
        self::assertSame(range(20, 11), array_column($queries, 'average_ms'));
        self::assertSame(['text' => 'SELECT * FROM `t20` WHERE `id` = ?', 'executions' => 2, 'average_ms' => 20, 'max_ms' => 20, 'total_ms' => 40], $queries[0]);
    }

    public function testADeniedReadIsAMissingPrerequisiteThatNamesTheTable(): void
    {
        $denied = new ConnectionException(new class ("SELECT command denied to user 'jmonitor'@'10.0.0.7' for table 'events_statements_summary_by_digest'", '42000', 1142) extends AbstractException {}, null);
        $database = new FakeDatabase(self::ENABLED + ['events_statements_summary_by_digest' => $denied, 'SHOW' => [['Variable_name' => 'Uptime', 'Value' => '1']]], connected: true);
        $link = new DbalLink(static fn() => $database);

        $failure = Failures::thrownBy(static fn() => (new MysqlSlowQueriesCollector($link, 'shop'))->collect());

        self::assertInstanceOf(MissingPrerequisiteException::class, $failure);
        self::assertSame(FailureKind::MissingPrerequisite, FailureKind::of($failure));
        self::assertSame('The database user of this connection may not read performance_schema.events_statements_summary_by_digest.', ErrorMessage::of($failure));
        self::assertSame([['Variable_name' => 'Uptime', 'Value' => '1']], $link->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'"));
    }

    public function testAnUnreachableServerStaysUnreachable(): void
    {
        $database = new FakeDatabase(['@@performance_schema' => new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused')]);
        $link = new DbalLink(static fn() => $database);

        $failure = Failures::thrownBy(static fn() => (new MysqlSlowQueriesCollector($link, 'shop'))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame($failure, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1')));
    }

    /**
     * @param list<array<string, string|int>> $digests
     */
    private static function database(array $digests): FakeDatabase
    {
        return new FakeDatabase(self::ENABLED + ['COUNT_STAR' => $digests]);
    }
}
