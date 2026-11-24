<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlActivityCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresqlActivityCollectorTest extends TestCase
{
    public function testItKeepsTheActivityOfItsDatabase(): void
    {
        $database = new FakeDatabase(DatabasePayloads::postgresqlActivityRows());
        $collector = new PostgresqlActivityCollector(new DbalLink(static fn() => $database));

        self::assertSame(DatabasePayloads::postgresqlActivity(), $collector->collect());
        self::assertSame('postgresql', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public function testNoQueryTextOfABlockedSessionIsKept(): void
    {
        $rows = DatabasePayloads::postgresqlActivityRows();
        $rows['pg_blocking_pids'][] = [
            'blocked_pid' => '4243',
            'blocked_wait_seconds' => '12',
            'blocked_query_sample' => "DELETE FROM session WHERE token = 'jm-token'",
            'blocking_pid' => '4100',
            'blocking_query_sample' => "ALTER ROLE app PASSWORD 'jm-secret'",
            'blocking_state' => null,
            'client_addr' => '10.0.0.7',
        ];
        $database = new FakeDatabase($rows);

        $activity = (new PostgresqlActivityCollector(new DbalLink(static fn() => $database)))->collect();

        self::assertSame([
            ['blocked_pid' => 4242, 'wait_seconds' => 45, 'blocking_pid' => 4100, 'blocking_state' => 'idle in transaction'],
            ['blocked_pid' => 4243, 'wait_seconds' => 12, 'blocking_pid' => 4100, 'blocking_state' => null],
        ], $activity['sessions']['blocked_queries']);
        $kept = serialize($activity);
        foreach (['s3cret', 'jane@example.com', 'jm-token', 'jm-secret', '10.0.0.7', 'query'] as $text) {
            self::assertStringNotContainsString($text, $kept);
        }
    }

    public function testItBootsTheLibraryCollector(): void
    {
        $database = new FakeDatabase(['buffers_backend' => new \RuntimeException('SQLSTATE[42703]: Undefined column: column "checkpoints_timed" does not exist')] + DatabasePayloads::postgresqlActivityRows(), connected: true);

        $activity = (new PostgresqlActivityCollector(new DbalLink(static fn() => $database)))->collect();

        self::assertSame(125_000, $activity['database_stats']['xact_commit']);
        self::assertContains('SHOW server_version_num', $database->queries);
        self::assertNotSame([], array_filter($database->queries, static fn(string $query): bool => str_contains($query, 'pg_stat_checkpointer')));
    }

    public function testTheBackgroundWriterIsNotKept(): void
    {
        $database = new FakeDatabase(DatabasePayloads::postgresqlActivityRows());

        $activity = (new PostgresqlActivityCollector(new DbalLink(static fn() => $database)))->collect();

        self::assertSame(['database_stats', 'connections', 'sessions', 'server_connections'], array_keys($activity));
    }

    public function testTheConnectionsOfTheWholeServerAreRead(): void
    {
        $database = new FakeDatabase(['server_connections' => [['server_connections' => '58']]] + DatabasePayloads::postgresqlActivityRows());

        $activity = (new PostgresqlActivityCollector(new DbalLink(static fn() => $database)))->collect();

        self::assertSame(58, $activity['server_connections']);
        self::assertSame(7, $activity['database_stats']['numbackends']);
        self::assertContains('SELECT COALESCE(SUM(numbackends), 0) AS server_connections FROM pg_stat_database', $database->queries);
    }

    public function testAnUnknownWaitStaysUnknown(): void
    {
        $rows = DatabasePayloads::postgresqlActivityRows();
        $rows['pg_blocking_pids'][0]['blocked_wait_seconds'] = null;
        unset($rows['pg_blocking_pids'][0]['blocking_state']);
        $database = new FakeDatabase($rows);

        $activity = (new PostgresqlActivityCollector(new DbalLink(static fn() => $database)))->collect();

        self::assertSame([['blocked_pid' => 4242, 'wait_seconds' => null, 'blocking_pid' => 4100, 'blocking_state' => null]], $activity['sessions']['blocked_queries']);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function unreadableServerConnections(): iterable
    {
        yield 'no row' => [[]];
        yield 'a null' => [[['server_connections' => null]]];
    }

    /**
     * @param list<array<string, mixed>> $answer
     */
    #[DataProvider('unreadableServerConnections')]
    public function testAnUnreadableCountOfTheServerConnectionsStaysUnknown(array $answer): void
    {
        $database = new FakeDatabase(['server_connections' => $answer] + DatabasePayloads::postgresqlActivityRows());

        $activity = (new PostgresqlActivityCollector(new DbalLink(static fn() => $database)))->collect();

        self::assertNull($activity['server_connections']);
    }

    public function testAnUnreachableServerStaysUnreachable(): void
    {
        $database = (new FakeDatabase())->failNext(new \RuntimeException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused'));
        $link = new DbalLink(static fn() => $database);

        $failure = Failures::thrownBy(static fn() => (new PostgresqlActivityCollector($link))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame($failure, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1')));
    }
}
