<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlActivityCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlDatabaseCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlSettingsCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlSlowQueriesCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\PostgresqlDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class PostgresqlDetectorTest extends TestCase
{
    private const POSTGRESQL = ['driver' => 'pdo_pgsql', 'host' => 'pg.internal', 'port' => null, 'user' => 'app', 'password' => 's3cr3t', 'dbname' => 'shop'];

    public function testEachConnectionIsAnInstanceWithItsDatabaseAndItsServer(): void
    {
        $detector = new PostgresqlDetector(self::instances(['default' => self::POSTGRESQL, 'reporting' => ['dbname' => 'reports'] + self::POSTGRESQL]), [], 'public', false);

        $detection = $detector->detect();

        self::assertSame(Component::Postgresql, $detector->component());
        self::assertTrue($detection->isFound());
        self::assertSame(['default', 'reporting'], array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances));
        self::assertSame(['shop', 'reports'], array_map(static fn(Instance $instance): ?string => $instance->detail, $detection->instances));
        self::assertSame(['pg.internal:5432', 'pg.internal:5432'], array_map(static fn(Instance $instance): ?string => $instance->server?->key, $detection->instances));
        self::assertSame(Component::Postgresql, $detection->instances[0]->component);
    }

    public function testEachCollectorHasItsLifetimeItsCostAndItsFlags(): void
    {
        $endOfLife = new FakeUrlFetcher(null);
        $detector = new PostgresqlDetector(self::instances(['default' => self::POSTGRESQL]), [], 'public', true, $endOfLife);

        $detection = $detector->detect();

        self::assertSame(Component::Postgresql, $detector->component());
        self::assertTrue($detection->isFound());
        $instance = $detection->instances[0];
        self::assertSame(Component::Postgresql, $instance->component);
        self::assertSame('default', $instance->id);
        self::assertSame('shop', $instance->detail);
        self::assertSame('pg.internal:5432', $instance->server?->key);
        self::assertNotNull($instance->collectors);
        self::assertSame(['postgresql', 'postgresql.settings', 'postgresql.database', 'postgresql.slow_queries', 'eol.postgresql'], $instance->collectors->names());
        self::assertSame([
            'postgresql' => [PostgresqlActivityCollector::class, Lifetime::Dynamic, CostClass::Network, true, false, false, false],
            'postgresql.settings' => [PostgresqlSettingsCollector::class, Lifetime::Static, CostClass::Network, false, false, false, false],
            'postgresql.database' => [PostgresqlDatabaseCollector::class, Lifetime::Minute, CostClass::Slow, false, false, false, false],
            'postgresql.slow_queries' => [PostgresqlSlowQueriesCollector::class, Lifetime::Minute, CostClass::Slow, false, false, true, false],
            'eol.postgresql' => [EndOfLifeCollector::class, Lifetime::Day, CostClass::Slow, false, true, false, false],
        ], self::flags($instance));
        self::assertSame(0, $endOfLife->calls);
    }

    public function testNoCollectorIsReadPerServer(): void
    {
        $detection = (new PostgresqlDetector(self::instances(['default' => self::POSTGRESQL, 'reporting' => ['dbname' => 'reports'] + self::POSTGRESQL]), [], 'public', true, new FakeUrlFetcher(null)))->detect();

        self::assertCount(2, $detection->instances);
        foreach ($detection->instances as $instance) {
            self::assertSame('pg.internal:5432', $instance->server?->key);
            foreach ($instance->collectors?->specs() ?? [] as $spec) {
                self::assertFalse($spec->perServer, $spec->name);
            }
        }
    }

    public function testWithoutEndOfLifeThereIsNoSupportLookup(): void
    {
        $detection = (new PostgresqlDetector(self::instances(['default' => self::POSTGRESQL]), [], 'public', false))->detect();

        self::assertSame(['postgresql', 'postgresql.settings', 'postgresql.database', 'postgresql.slow_queries'], $detection->instances[0]->collectors?->names());
    }

    public function testTheSchemaReachesTheDatabaseCollector(): void
    {
        $container = new Container();
        $container->set('doctrine.dbal.default_connection', new FakeDatabase());
        $collectors = (new PostgresqlDetector(self::instances(['default' => self::POSTGRESQL], $container), [], 'billing', false))->detect()->instances[0]->collectors;

        $database = null;
        foreach ($collectors?->specs() ?? [] as $spec) {
            if ($spec->name === 'postgresql.database') {
                $database = $spec->collector->collect();
            }
        }

        self::assertSame('billing', $database['schema'] ?? null);
    }

    public function testTheCollectorsOfOneConnectionShareOneLink(): void
    {
        $database = new FakeDatabase();
        $container = new class extends Container {
            public int $asked = 0;

            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                ++$this->asked;

                return parent::get($id, $invalidBehavior);
            }
        };
        $container->set('doctrine.dbal.default_connection', $database);
        $collectors = (new PostgresqlDetector(self::instances(['default' => self::POSTGRESQL], $container), [], 'public', false))->detect()->instances[0]->collectors;
        self::assertNotNull($collectors);

        foreach ($collectors->specs() as $spec) {
            $spec->collector->collect();
        }
        $collectors->release();

        self::assertSame(1, $container->asked);
        self::assertSame(1, $database->closes);
        self::assertGreaterThanOrEqual(10, $database->calls());
    }

    public function testOnlyTheConfiguredConnectionsAreRead(): void
    {
        $detection = (new PostgresqlDetector(self::instances(['default' => self::POSTGRESQL, 'reporting' => ['dbname' => 'reports'] + self::POSTGRESQL]), ['reporting' => []], 'public', false))->detect();

        self::assertSame(['reporting'], array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances));
        self::assertSame('reports', $detection->instances[0]->detail);
    }

    public function testWithoutAPostgresqlConnectionItSaysWhy(): void
    {
        $mysql = (new PostgresqlDetector(self::instances(['default' => ['driver' => 'pdo_mysql', 'host' => 'db.internal', 'dbname' => 'shop']]), [], 'public', false))->detect();
        $none = (new PostgresqlDetector(new DatabaseInstances(new DoctrineConnections(new ServiceLocator([]), []), new Container(), [], []), [], 'public', false))->detect();

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
