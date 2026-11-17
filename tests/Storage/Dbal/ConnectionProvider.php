<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;

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
            $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);
        }

        return $sets;
    }

    private static function connection(string $name, ?string $url = null): Connection
    {
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        if ($url === null) {
            $params = ['driver' => 'pdo_sqlite', 'path' => tempnam(sys_get_temp_dir(), 'jmonitor_') ?: ':memory:'];
        } else {
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url);
        }

        return self::$connections[$name] = DriverManager::getConnection($params);
    }
}
