<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\PostgresqlDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class PostgresqlDetectorTest extends TestCase
{
    private const POSTGRESQL = ['driver' => 'pdo_pgsql', 'host' => 'pg.internal', 'port' => null, 'user' => 'app', 'password' => 's3cr3t', 'dbname' => 'shop'];

    public function testEachConnectionIsAnInstanceWithItsDatabaseAndItsServer(): void
    {
        $detector = new PostgresqlDetector(self::instances(['default' => self::POSTGRESQL, 'reporting' => ['dbname' => 'reports'] + self::POSTGRESQL]), []);

        $detection = $detector->detect();

        self::assertSame(Component::Postgresql, $detector->component());
        self::assertTrue($detection->isFound());
        self::assertSame(['default', 'reporting'], array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances));
        self::assertSame(['shop', 'reports'], array_map(static fn(Instance $instance): ?string => $instance->detail, $detection->instances));
        self::assertSame(['pg.internal:5432', 'pg.internal:5432'], array_map(static fn(Instance $instance): ?string => $instance->server?->key, $detection->instances));
        self::assertSame(Component::Postgresql, $detection->instances[0]->component);
    }

    public function testOnlyTheConfiguredConnectionsAreRead(): void
    {
        $detection = (new PostgresqlDetector(self::instances(['default' => self::POSTGRESQL, 'reporting' => ['dbname' => 'reports'] + self::POSTGRESQL]), ['reporting' => []]))->detect();

        self::assertSame(['reporting'], array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances));
        self::assertSame('reports', $detection->instances[0]->detail);
    }

    public function testWithoutAPostgresqlConnectionItSaysWhy(): void
    {
        $mysql = (new PostgresqlDetector(self::instances(['default' => ['driver' => 'pdo_mysql', 'host' => 'db.internal', 'dbname' => 'shop']]), []))->detect();
        $none = (new PostgresqlDetector(new DatabaseInstances(new DoctrineConnections(new ServiceLocator([]), []), new Container(), [], []), []))->detect();

        self::assertSame('No PostgreSQL Doctrine connection found.', self::error($mysql));
        self::assertSame('No Doctrine DBAL connection is configured.', self::error($none));
    }

    /**
     * @param array<string, array<string, mixed>> $params
     */
    private static function instances(array $params, ?Container $container = null): DatabaseInstances
    {
        $names = array_map(strval(...), array_keys($params));

        return new DatabaseInstances(
            new DoctrineConnections(new ServiceLocator(array_map(static fn(array $connection): \Closure => static fn(): \ArrayObject => new \ArrayObject($connection), $params)), $names),
            $container ?? new Container(),
            array_combine($names, array_map(static fn(string $name): string => 'doctrine.dbal.' . $name . '_connection', $names)),
            [],
        );
    }

    private static function error(Detection $detection): ?string
    {
        self::assertFalse($detection->isFound());

        return $detection->error;
    }
}
