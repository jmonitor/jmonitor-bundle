<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlSizesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlSlowQueriesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlVariablesCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\MysqlDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class MysqlDetectorTest extends TestCase
{
    private const MYSQL = ['driver' => 'pdo_mysql', 'host' => 'db.internal', 'port' => null, 'user' => 'root', 'password' => 's3cr3t', 'dbname' => 'shop'];

    public function testEachCollectorHasItsLifetimeItsCostAndItsFlags(): void
    {
        $endOfLife = new FakeUrlFetcher(null);
        $detector = new MysqlDetector(self::instances(['default' => self::MYSQL]), [], true, $endOfLife);

        $detection = $detector->detect();

        self::assertSame(Component::Mysql, $detector->component());
        self::assertTrue($detection->isFound());
        $instance = $detection->instances[0];
        self::assertSame(Component::Mysql, $instance->component);
        self::assertSame('default', $instance->id);
        self::assertSame('shop', $instance->detail);
        self::assertSame('db.internal:3306', $instance->server?->key);
        self::assertNotNull($instance->collectors);
        self::assertSame(['mysql', 'mysql.variables', 'mysql.sizes', 'mysql.slow_queries', 'eol.mysql', 'eol.mariadb'], $instance->collectors->names());
        self::assertSame([
            'mysql' => [MysqlStatusCollector::class, Lifetime::Dynamic, CostClass::Network, true, false, false, true],
            'mysql.variables' => [MysqlVariablesCollector::class, Lifetime::Static, CostClass::Network, false, false, false, true],
            'mysql.sizes' => [MysqlSizesCollector::class, Lifetime::Static, CostClass::Slow, false, false, true, false],
            'mysql.slow_queries' => [MysqlSlowQueriesCollector::class, Lifetime::Minute, CostClass::Slow, false, false, true, false],
            'eol.mysql' => [EndOfLifeCollector::class, Lifetime::Day, CostClass::Slow, false, true, false, false],
            'eol.mariadb' => [EndOfLifeCollector::class, Lifetime::Day, CostClass::Slow, false, true, false, false],
        ], self::flags($instance));
        self::assertSame(0, $endOfLife->calls);
    }

    public function testWithoutEndOfLifeThereIsNoSupportLookup(): void
    {
        $detection = (new MysqlDetector(self::instances(['default' => self::MYSQL]), [], false))->detect();

        self::assertSame(['mysql', 'mysql.variables', 'mysql.sizes', 'mysql.slow_queries'], $detection->instances[0]->collectors?->names());
    }

    public function testAConnectionWithoutDatabaseHasNoDatabaseCollector(): void
    {
        $withoutName = self::MYSQL;
        unset($withoutName['dbname']);

        $detection = (new MysqlDetector(self::instances(['default' => $withoutName, 'empty' => ['dbname' => ''] + self::MYSQL]), [], false))->detect();

        self::assertSame(['mysql', 'mysql.variables'], $detection->instances[0]->collectors?->names());
        self::assertSame(['mysql', 'mysql.variables'], $detection->instances[1]->collectors?->names());
    }

    public function testTheCollectorsOfOneConnectionShareOneLink(): void
    {
        $database = new FakeDatabase(['@@performance_schema' => [['enabled' => '1']]]);
        $container = new class extends Container {
            public int $asked = 0;

            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                ++$this->asked;

                return parent::get($id, $invalidBehavior);
            }
        };
        $container->set('doctrine.dbal.default_connection', $database);
        $collectors = (new MysqlDetector(self::instances(['default' => self::MYSQL], $container), [], false))->detect()->instances[0]->collectors;
        self::assertNotNull($collectors);

        foreach ($collectors->specs() as $spec) {
            $spec->collector->collect();
        }
        $collectors->release();

        self::assertSame(1, $container->asked);
        self::assertSame(1, $database->closes);
        self::assertGreaterThanOrEqual(8, $database->calls());
    }

    public function testOnlyTheConfiguredConnectionsAreRead(): void
    {
        $detection = (new MysqlDetector(self::instances(['default' => self::MYSQL, 'reporting' => ['dbname' => 'reports'] + self::MYSQL]), ['reporting' => []], false))->detect();

        self::assertSame(['reporting'], array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances));
        self::assertSame('reports', $detection->instances[0]->detail);
    }

    public function testWithoutAMysqlConnectionItSaysWhy(): void
    {
        $sqlite = (new MysqlDetector(self::instances(['default' => ['driver' => 'pdo_sqlite', 'path' => '/srv/app/var/data.sqlite']]), [], false))->detect();
        $none = (new MysqlDetector(new DatabaseInstances(new DoctrineConnections(new ServiceLocator([]), []), new Container(), [], []), [], false))->detect();

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

    /**
     * @return array<string, array{class-string, Lifetime, CostClass, bool, bool, bool, bool}> the collector, lifetime, cost, delta, shared, detail only and per server flags of each spec
     */
    private static function flags(Instance $instance): array
    {
        $flags = [];

        foreach ($instance->collectors?->specs() ?? [] as $spec) {
            \assert($spec instanceof CollectorSpec);
            $flags[$spec->name] = [$spec->collector::class, $spec->lifetime, $spec->cost, $spec->trackDelta, $spec->shared, $spec->detailOnly, $spec->perServer];
        }

        return $flags;
    }

    private static function error(Detection $detection): ?string
    {
        self::assertFalse($detection->isFound());

        return $detection->error;
    }
}
