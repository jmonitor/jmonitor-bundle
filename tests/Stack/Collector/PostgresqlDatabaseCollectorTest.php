<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlDatabaseCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use PHPUnit\Framework\TestCase;

final class PostgresqlDatabaseCollectorTest extends TestCase
{
    public function testItKeepsTheSizeOfTheDatabaseAndTheTablesOfItsSchema(): void
    {
        $database = new FakeDatabase([
            'pg_database_size' => [['db_size' => 83_886_080]],
            'n_live_tup' => [['table_count' => 12, 'live_tuples' => 250_000, 'dead_tuples' => 4_000, 'seq_scans' => 300, 'idx_scans' => 90_000]],
            'pg_total_relation_size' => [['total_size' => '62914560', 'indexes_size' => '20971520']],
        ]);
        $collector = new PostgresqlDatabaseCollector(new DbalLink(static fn() => $database), 'app');

        self::assertSame(DatabasePayloads::postgresqlDatabase('app'), $collector->collect());
        self::assertSame('postgresql.database', $collector->getName());
        self::assertSame(1, $collector->getVersion());
        self::assertSame('SELECT 1 FROM pg_stat_user_tables LIMIT 1', $database->queries[0]);
        self::assertSame(4, $database->calls());
    }

    public function testAnUnreachableServerStaysUnreachable(): void
    {
        $database = (new FakeDatabase())->failNext(new \RuntimeException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused'));
        $link = new DbalLink(static fn() => $database);

        $failure = Failures::thrownBy(static fn() => (new PostgresqlDatabaseCollector($link, 'public'))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame($failure, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1')));
    }
}
