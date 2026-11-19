<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\KeysTable;

final class ConnectionProvider
{
    /** @var array<string, Connection> */
    private static array $connections = [];

    /** @return array<string, array{Connection}> */
    public static function all(): array
    {
        $sets = ['sqlite' => [self::connection('sqlite')]];

        foreach (['mysql' => 'JMONITOR_TEST_MYSQL_URL', 'pgsql' => 'JMONITOR_TEST_PGSQL_URL'] as $name => $variable) {
            if (is_string($url = getenv($variable)) && $url !== '') {
                $sets[$name] = [self::connection($name, $url)];
            }
        }

        foreach ($sets as [$connection]) {
            self::dropTables($connection);
        }

        return $sets;
    }

    /**
     * A new connection to each available database, with a recorder of its prepared statements.
     *
     * @return array<string, array{Connection, StatementRecorder}>
     */
    public static function recorded(): array
    {
        $sets = ['sqlite' => StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true])];

        foreach (['mysql' => 'JMONITOR_TEST_MYSQL_URL', 'pgsql' => 'JMONITOR_TEST_PGSQL_URL'] as $name => $variable) {
            if (is_string($url = getenv($variable)) && $url !== '') {
                $sets[$name] = StatementRecorder::connect(self::parse($url));
            }
        }

        return $sets;
    }

    public static function dropTables(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);
        $connection->executeStatement('DROP TABLE IF EXISTS ' . KeysTable::NAME);
    }

    private static function connection(string $name, ?string $url = null): Connection
    {
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        if ($url === null) {
            $params = ['driver' => 'pdo_sqlite', 'path' => tempnam(sys_get_temp_dir(), 'jmonitor_') ?: ':memory:'];
        } else {
            $params = self::parse($url);
        }

        return self::$connections[$name] = DriverManager::getConnection($params);
    }

    /** @return array<string, mixed> */
    public static function parse(string $url): array
    {
        return (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url);
    }
}
