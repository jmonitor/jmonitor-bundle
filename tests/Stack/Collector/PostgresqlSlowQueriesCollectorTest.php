<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlSlowQueriesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use PHPUnit\Framework\TestCase;

final class PostgresqlSlowQueriesCollectorTest extends TestCase
{
    public function testItKeepsTheSlowestStatements(): void
    {
        $database = self::database([DatabasePayloads::statement('SELECT * FROM "order" WHERE status = $1', 120, 300.46, 1200.5, 36055.2)]);
        $collector = new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => $database));

        self::assertSame(['queries' => [
            ['text' => 'SELECT * FROM "order" WHERE status = $1', 'executions' => 120, 'average_ms' => 300, 'max_ms' => 1201, 'total_ms' => 36055],
        ], 'hidden' => 0], $collector->collect());
        self::assertSame('postgresql.slow_queries', $collector->getName());
        self::assertSame(1, $collector->getVersion());
        self::assertStringContainsString('calls >= 1', $database->queries[0]);
        self::assertStringContainsString('ORDER BY mean_exec_time DESC', $database->queries[0]);
        self::assertStringContainsString('LIMIT 30', $database->queries[0]);
    }

    public function testOnlyStatementsWithPlaceholdersAreKept(): void
    {
        $database = self::database([
            DatabasePayloads::statement("CREATE ROLE app PASSWORD 's3cret'", 1, 900),
            DatabasePayloads::statement("ALTER ROLE app PASSWORD 'n3w-s3cret'", 1, 800),
            DatabasePayloads::statement("DO \$\$BEGIN PERFORM set_config('app.key', 'k3y', false); END\$\$", 1, 700),
            DatabasePayloads::statement("COPY account FROM '/srv/import/accounts.csv'", 1, 600),
            DatabasePayloads::statement("SET app.key = 'k3y'", 1, 500),
            DatabasePayloads::statement('<insufficient privilege>', 4, 400),
            DatabasePayloads::statement('select * from account where id = $1', 40, 30),
            DatabasePayloads::statement("  \n WITH recent AS (SELECT * FROM \"order\" WHERE created_at > \$1) SELECT count(*) FROM recent", 10, 20),
            DatabasePayloads::statement('INSERT INTO "order" (id, status) VALUES ($1, $2)', 10, 10),
            DatabasePayloads::statement('UPDATE "order" SET status = $1 WHERE id = $2', 10, 9),
            DatabasePayloads::statement('DELETE FROM session WHERE expires_at < $1', 10, 8),
            DatabasePayloads::statement('CALL refresh_totals($1)', 10, 7),
        ]);

        $queries = (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => $database)))->collect()['queries'];

        self::assertSame([
            'select * from account where id = $1',
            'WITH recent AS (SELECT * FROM "order" WHERE created_at > $1) SELECT count(*) FROM recent',
            'INSERT INTO "order" (id, status) VALUES ($1, $2)',
            'UPDATE "order" SET status = $1 WHERE id = $2',
            'DELETE FROM session WHERE expires_at < $1',
        ], array_column($queries, 'text'));
    }

    public function testAStatementKeptWithItsValuesHasThemReplaced(): void
    {
        $database = self::database([DatabasePayloads::statement("SELECT pg_sleep(10), 'jm-literal' AS v", 1, 10_000)]);

        $queries = (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => $database)))->collect()['queries'];

        self::assertSame(['SELECT pg_sleep(?), ? AS v'], array_column($queries, 'text'));
    }

    public function testACommentIsNotKept(): void
    {
        $database = self::database([DatabasePayloads::statement('SELECT pg_sleep($1) /* api_key=jm-secret */', 2, 20)]);

        $queries = (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => $database)))->collect()['queries'];

        self::assertSame(['SELECT pg_sleep($1)'], array_column($queries, 'text'));
    }

    public function testAStatementThatCannotBeReadIsLeftOut(): void
    {
        $database = self::database([
            DatabasePayloads::statement("SELECT * FROM account WHERE note = 'jm-cut in the middle of a lit", 1, 900),
            DatabasePayloads::statement('SELECT * FROM product WHERE id = $1', 40, 12),
        ]);

        $queries = (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => $database)))->collect()['queries'];

        self::assertSame(['SELECT * FROM product WHERE id = $1'], array_column($queries, 'text'));
    }

    public function testThePlaceholdersOfANormalizedStatementStay(): void
    {
        $database = self::database([DatabasePayloads::statement('SELECT * FROM "order" WHERE customer_id = $1 AND status = $2 LIMIT $3', 3, 40)]);

        $queries = (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => $database)))->collect()['queries'];

        self::assertSame(['SELECT * FROM "order" WHERE customer_id = $1 AND status = $2 LIMIT $3'], array_column($queries, 'text'));
    }

    public function testTheStatementsHiddenFromTheRoleAreCounted(): void
    {
        $rows = [
            DatabasePayloads::statement('<insufficient privilege>', 4, 900),
            DatabasePayloads::statement('SELECT * FROM product WHERE id = $1', 40, 800),
            DatabasePayloads::statement('<insufficient privilege>', 4, 700),
        ];
        for ($i = 20; $i > 0; --$i) {
            $rows[] = DatabasePayloads::statement('SELECT * FROM t' . $i . ' WHERE id = $1', 2, $i);
        }
        $rows[] = DatabasePayloads::statement('<insufficient privilege>', 1, 0.5);

        $slowQueries = (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => self::database($rows))))->collect();

        self::assertSame(3, $slowQueries['hidden']);
        self::assertCount(10, $slowQueries['queries']);
        self::assertSame('SELECT * FROM product WHERE id = $1', $slowQueries['queries'][0]['text']);
    }

    public function testItsOwnQueriesAreLeftOut(): void
    {
        $database = self::database([
            DatabasePayloads::statement('SELECT numbackends, xact_commit FROM pg_stat_database WHERE datname = current_database()', 3, 900),
            DatabasePayloads::statement('SELECT state, COUNT(*) AS count FROM pg_stat_activity WHERE datname = current_database() GROUP BY state', 3, 800),
            DatabasePayloads::statement('SELECT LEFT(query, $1) AS query_sample FROM pg_stat_statements', 3, 700),
            DatabasePayloads::statement('SELECT pg_database_size(current_database()) AS db_size', 3, 600),
            DatabasePayloads::statement('SELECT COALESCE(SUM(pg_total_relation_size(relid)), $1) AS total_size FROM pg_stat_user_tables', 3, 500),
            DatabasePayloads::statement('SELECT COALESCE(SUM(pg_indexes_size(relid)), $1) AS indexes_size FROM x', 3, 450),
            DatabasePayloads::statement('SELECT name, setting, unit FROM pg_settings WHERE name IN ($1, $2)', 3, 400),
            DatabasePayloads::statement('SELECT a.pid FROM x JOIN LATERAL unnest(pg_blocking_pids(a.pid)) AS blocking(pid) ON $1', 3, 300),
            DatabasePayloads::statement("SELECT\n                     a.pid                                              AS blocked_pid,\n                     LEFT(a.query, \$2)                                 AS blocked_query_sample,\n                     bl.pid                                             AS blocking_pid", 3, 200),
            DatabasePayloads::statement('SELECT * FROM product WHERE id = $1', 40, 12),
        ]);

        $queries = (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => $database)))->collect()['queries'];

        self::assertSame(['SELECT * FROM product WHERE id = $1'], array_column($queries, 'text'));
    }

    public function testOnlyTenAreKept(): void
    {
        $rows = [];
        for ($i = 30; $i > 0; --$i) {
            $rows[] = DatabasePayloads::statement('SELECT * FROM t' . $i . ' WHERE id = $1', 2, $i);
        }

        $queries = (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => self::database($rows))))->collect()['queries'];

        self::assertCount(10, $queries);
        self::assertSame(range(30, 21), array_column($queries, 'average_ms'));
        self::assertSame(['text' => 'SELECT * FROM t30 WHERE id = $1', 'executions' => 2, 'average_ms' => 30, 'max_ms' => 30, 'total_ms' => 60], $queries[0]);
    }

    public function testTheLibraryCollectorIsNotBooted(): void
    {
        $database = self::database([]);

        (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => $database)))->collect();

        self::assertSame(1, $database->calls());
        self::assertStringContainsString('FROM pg_stat_statements', $database->queries[0]);
    }

    public function testWithoutTheExtensionItIsAMissingPrerequisite(): void
    {
        $missing = new TableNotFoundException(new class ('SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "pg_stat_statements" does not exist', '42P01', 7) extends AbstractException {}, null);

        $failure = self::failure($missing);

        self::assertInstanceOf(MissingPrerequisiteException::class, $failure);
        self::assertSame(FailureKind::MissingPrerequisite, FailureKind::of($failure));
        self::assertSame('pg_stat_statements is not installed in this database: run CREATE EXTENSION pg_stat_statements.', ErrorMessage::of($failure));
    }

    public function testAnExtensionThatIsNotLoadedIsAMissingPrerequisite(): void
    {
        $notLoaded = new DriverException(new class ('SQLSTATE[55000]: Object not in prerequisite state: 7 ERROR:  pg_stat_statements must be loaded via "shared_preload_libraries"', '55000', 7) extends AbstractException {}, null);

        $failure = self::failure($notLoaded);

        self::assertInstanceOf(MissingPrerequisiteException::class, $failure);
        self::assertSame('pg_stat_statements is not loaded: add it to shared_preload_libraries and restart PostgreSQL.', ErrorMessage::of($failure));
    }

    public function testADeniedReadIsAMissingPrerequisite(): void
    {
        $denied = new DriverException(new class ('SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  permission denied for view pg_stat_statements', '42501', 7) extends AbstractException {}, null);

        $failure = self::failure($denied);

        self::assertInstanceOf(MissingPrerequisiteException::class, $failure);
        self::assertSame('The role of this connection may not read pg_stat_statements.', ErrorMessage::of($failure));
    }

    public function testAnotherErrorIsKeptAsItIs(): void
    {
        $other = new DriverException(new class ('SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to statement timeout', '57014', 7) extends AbstractException {}, null);

        self::assertSame($other, self::failure($other));
    }

    public function testAnUnreachableServerStaysUnreachable(): void
    {
        $database = (new FakeDatabase())->failNext(new \RuntimeException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused'));
        $link = new DbalLink(static fn() => $database);

        $failure = Failures::thrownBy(static fn() => (new PostgresqlSlowQueriesCollector($link))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame($failure, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1')));
    }

    /**
     * @param list<array<string, string|int>> $statements
     */
    private static function database(array $statements): FakeDatabase
    {
        return new FakeDatabase(['pg_stat_statements' => $statements]);
    }

    private static function failure(\Throwable $thrown): \Throwable
    {
        $database = new FakeDatabase(['pg_stat_statements' => $thrown], connected: true);

        return Failures::thrownBy(static fn() => (new PostgresqlSlowQueriesCollector(new DbalLink(static fn() => $database)))->collect());
    }
}
