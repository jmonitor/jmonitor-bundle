<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\MysqlDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MysqlDetectorTest extends TestCase
{
    private const MYSQL = ['driver' => 'pdo_mysql', 'host' => 'db.internal', 'port' => null, 'user' => 'root', 'password' => 's3cr3t', 'dbname' => 'shop'];

    public function testEachMysqlConnectionIsAnInstanceOnItsServer(): void
    {
        $detector = new MysqlDetector(self::instances(['default' => self::MYSQL]), []);

        $detection = $detector->detect();

        self::assertSame(Component::Mysql, $detector->component());
        self::assertTrue($detection->isFound());
        self::assertSame(Component::Mysql, $detection->instances[0]->component);
        self::assertSame('default', $detection->instances[0]->id);
        self::assertSame('shop', $detection->instances[0]->detail);
        self::assertSame('db.internal:3306', $detection->instances[0]->server?->key);
    }

    public function testOnlyTheConfiguredConnectionsAreRead(): void
    {
        $detection = (new MysqlDetector(self::instances(['default' => self::MYSQL, 'reporting' => ['dbname' => 'reports'] + self::MYSQL]), ['reporting' => []]))->detect();

        self::assertSame(['reporting'], array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances));
        self::assertSame('reports', $detection->instances[0]->detail);
    }

    public function testWithoutAMysqlConnectionItSaysWhy(): void
    {
        $sqlite = (new MysqlDetector(self::instances(['default' => ['driver' => 'pdo_sqlite', 'path' => '/srv/app/var/data.sqlite']]), []))->detect();
        $none = (new MysqlDetector(new DatabaseInstances(new DoctrineConnections(new ServiceLocator([]), []), new Container(), [], []), []))->detect();

        self::assertSame('No MySQL Doctrine connection found.', self::error($sqlite));
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
