<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlSizesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlSlowQueriesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlVariablesCollector;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\ConnectionProvider;
use PHPUnit\Framework\TestCase;

final class MysqlLiveTest extends TestCase
{
    private const TABLE = 'jmonitor_stack_notes';

    private Connection $connection;

    private string $database;

    protected function setUp(): void
    {
        $url = getenv('JMONITOR_TEST_MYSQL_URL');

        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('JMONITOR_TEST_MYSQL_URL is not set.');
        }

        $params = ConnectionProvider::parse($url);
        $this->connection = DriverManager::getConnection($params);
        $this->database = (string) ($params['dbname'] ?? '');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(64) NOT NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
            $this->connection->close();
        }
    }

    public function testTheStatusAndTheVariablesAreRead(): void
    {
        $status = (new MysqlStatusCollector($this->link()))->collect();
        $variables = (new MysqlVariablesCollector($this->link()))->collect();

        self::assertArrayHasKey('Threads_connected', $status);
        self::assertArrayHasKey('Uptime', $status);
        self::assertArrayHasKey('version', $variables);
        self::assertArrayHasKey('max_connections', $variables);
        self::assertArrayNotHasKey('slow_query_log_file', $variables);
    }

    public function testTheSizesOfTheDatabaseAreRead(): void
    {
        $sizes = (new MysqlSizesCollector($this->link(), $this->database))->collect();

        self::assertSame(['data_length', 'index_length'], array_keys($sizes));
        self::assertIsInt($sizes['data_length']);
        self::assertIsInt($sizes['index_length']);
    }

    public function testTheSlowestQueriesHoldNoLiteral(): void
    {
        $literal = 'jm-literal-' . bin2hex(random_bytes(6));

        for ($i = 0; $i < 10; ++$i) {
            $this->connection->fetchAllAssociative('SELECT id, note FROM ' . self::TABLE . " WHERE note = '" . $literal . "'");
        }

        $queries = (new MysqlSlowQueriesCollector($this->link(), $this->database))->collect()['queries'];

        self::assertNotSame([], $queries);
        foreach ($queries as $query) {
            self::assertStringNotContainsString($literal, $query['text']);
            self::assertStringNotContainsStringIgnoringCase('performance_schema', $query['text']);
        }
    }

    private function link(): DbalLink
    {
        $connection = $this->connection;

        return new DbalLink(static fn(): Connection => $connection);
    }
}
