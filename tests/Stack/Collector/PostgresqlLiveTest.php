<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlActivityCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlDatabaseCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlSettingsCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlSlowQueriesCollector;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\ConnectionProvider;
use PHPUnit\Framework\TestCase;

final class PostgresqlLiveTest extends TestCase
{
    private const TABLE = 'jmonitor_stack_notes';

    /** Slower than any query the rest of the suite leaves on the server, so that it ranks among the slowest kept. */
    private const PLAYED_SECONDS = 0.1;

    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('JMONITOR_TEST_PGSQL_URL');

        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('JMONITOR_TEST_PGSQL_URL is not set.');
        }

        $this->connection = DriverManager::getConnection(ConnectionProvider::parse($url));
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id SERIAL PRIMARY KEY, note VARCHAR(64) NOT NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
            $this->connection->close();
        }
    }

    public function testTheActivityTheSettingsAndTheDatabaseAreRead(): void
    {
        $activity = (new PostgresqlActivityCollector($this->link()))->collect();
        $settings = (new PostgresqlSettingsCollector($this->link()))->collect();
        $database = (new PostgresqlDatabaseCollector($this->link(), 'public'))->collect();

        self::assertIsInt($activity['database_stats']['xact_commit'] ?? null);
        self::assertNotSame([], $activity['connections']);
        self::assertGreaterThanOrEqual(1, $activity['server_connections']);
        self::assertArrayHasKey('max_connections', $settings);
        self::assertArrayHasKey('server_version', $settings);
        self::assertIsInt($database['db_size']);
        self::assertSame('public', $database['schema']);
    }

    public function testTheSlowestQueriesHoldNoLiteralNorPassword(): void
    {
        $literal = 'jm-literal-' . bin2hex(random_bytes(6));
        $role = 'jm_probe_' . bin2hex(random_bytes(6));
        $secret = 'jm-secret-' . bin2hex(random_bytes(6));
        $comment = 'jm-comment-' . bin2hex(random_bytes(6));

        for ($i = 0; $i < 10; ++$i) {
            $this->connection->fetchAllAssociative('SELECT pg_sleep(' . self::PLAYED_SECONDS . ') AS waited, (SELECT COUNT(*) FROM ' . self::TABLE . " WHERE note = '" . $literal . "') AS notes");
        }
        $this->connection->fetchAllAssociative('SELECT pg_sleep(' . self::PLAYED_SECONDS . ') AS waited /* api_key=' . $comment . ' */');
        $this->connection->executeStatement('CREATE ROLE ' . $role . " PASSWORD '" . $secret . "'");
        $this->connection->executeStatement('DROP ROLE ' . $role);

        try {
            $queries = (new PostgresqlSlowQueriesCollector($this->link()))->collect()['queries'];
        } catch (MissingPrerequisiteException $e) {
            self::markTestSkipped($e->getMessage() . ' Recreate the test database: docker compose -f tests/compose.yaml up -d --wait --force-recreate --renew-anon-volumes postgres');
        }
        $played = array_filter($queries, static fn(array $query): bool => str_contains($query['text'], self::TABLE) && str_contains($query['text'], '$'));

        self::assertNotSame([], $played, implode("\n", array_column($queries, 'text')));
        foreach ($queries as $query) {
            self::assertStringNotContainsString($literal, $query['text']);
            self::assertStringNotContainsString($secret, $query['text']);
            self::assertStringNotContainsString($role, $query['text']);
            self::assertStringNotContainsString($comment, $query['text']);
            self::assertStringNotContainsString('pg_stat_', $query['text']);
        }
    }

    public function testARoleWithoutPrivilegeCountsTheSessionsItCannotSee(): void
    {
        $role = 'jm_probe_' . bin2hex(random_bytes(6));
        $params = ConnectionProvider::parse((string) getenv('JMONITOR_TEST_PGSQL_URL'));
        $password = bin2hex(random_bytes(16));
        $this->connection->executeStatement('CREATE ROLE ' . $role . " LOGIN PASSWORD '" . $password . "'");
        $other = DriverManager::getConnection($params);
        $limited = DriverManager::getConnection(['user' => $role, 'password' => $password] + $params);

        try {
            $other->fetchOne('SELECT 1');
            $seenByItsOwner = (new PostgresqlActivityCollector($this->link()))->collect();
            $seenByAnother = (new PostgresqlActivityCollector(new DbalLink(static fn(): Connection => $limited)))->collect();
        } finally {
            $limited->close();
            $other->close();
            $this->connection->executeStatement('DROP ROLE ' . $role);
        }

        self::assertSame(0, $seenByItsOwner['hidden_sessions']);
        self::assertGreaterThanOrEqual(1, $seenByAnother['hidden_sessions'], 'the sessions of the role of the test');
    }

    private function link(): DbalLink
    {
        $connection = $this->connection;

        return new DbalLink(static fn(): Connection => $connection);
    }
}
