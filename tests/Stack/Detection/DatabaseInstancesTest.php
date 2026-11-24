<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ConnectionTarget;
use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class DatabaseInstancesTest extends TestCase
{
    private const MYSQL = ['driver' => 'pdo_mysql', 'host' => 'db.internal', 'port' => null, 'user' => 'root', 'password' => 's3cr3t'];
    private const POSTGRESQL = ['driver' => 'pdo_pgsql', 'host' => 'pg.internal', 'port' => null, 'user' => 'root', 'password' => 's3cr3t'];
    private const SQLITE = ['driver' => 'pdo_sqlite', 'host' => 'localhost', 'port' => null, 'user' => 'root', 'password' => null, 'path' => '/srv/app/var/data.sqlite'];

    public function testConfiguredConnectionsAreShownInTheirOrder(): void
    {
        $options = [];
        $instances = self::instances([
            'default' => ['dbname' => 'shop'] + self::MYSQL,
            'reporting' => ['dbname' => 'reports'] + self::POSTGRESQL,
            'legacy' => ['dbname' => 'old'] + self::MYSQL,
        ]);

        $detection = $instances->detect(Component::System, 'mysql', 'MySQL', ['reporting' => [], 'default' => ['limit' => 3]], static function (ConnectionTarget $target, DbalLink $link, array $given) use (&$options): array {
            $options[$target->name] = $given;

            return [];
        }, false, 3306);

        self::assertSame(['reporting', 'default'], self::ids($detection));
        self::assertSame('Doctrine connection "reporting" is not a MySQL connection.', $detection->instances[0]->error);
        self::assertSame('reports', $detection->instances[0]->detail);
        self::assertTrue($detection->instances[1]->isFound());
        self::assertSame('shop', $detection->instances[1]->detail);
        self::assertSame(['default' => ['limit' => 3]], $options);
    }

    public function testTheErrorsOfAConfiguredConnection(): void
    {
        $none = (new DatabaseInstances(new DoctrineConnections(new ServiceLocator([]), []), new Container(), [], []))
            ->detect(Component::System, 'mysql', 'MySQL', ['default' => []], self::noCollector(), false, 3306);
        $instances = self::instances(['default' => self::MYSQL, 'sqlite' => self::SQLITE], ['broken' => static fn(): never => throw new EnvNotFoundException('Environment variable not found: "NOPE_URL".')]);
        $detection = $instances->detect(Component::System, 'mysql', 'MySQL', ['missing' => [], 'broken' => [], 'sqlite' => []], self::noCollector(), false, 3306);

        self::assertSame(['default'], self::ids($none));
        self::assertSame('No Doctrine DBAL connection is configured.', $none->instances[0]->error);
        self::assertSame(['missing', 'broken', 'sqlite'], self::ids($detection));
        self::assertSame([
            'Unknown Doctrine connection "missing".',
            'The parameters of this connection cannot be read: an environment variable is missing ("NOPE_URL").',
            'Doctrine connection "sqlite" is not a MySQL connection.',
        ], array_map(static fn(Instance $instance): ?string => $instance->error, $detection->instances));
    }

    public function testEveryConnectionOfTheDriverIsFoundWhenNoneIsConfigured(): void
    {
        $detection = self::instances([
            'default' => ['dbname' => 'shop'] + self::MYSQL,
            'cache' => self::SQLITE,
            'reporting' => ['dbname' => 'reports'] + self::POSTGRESQL,
            '2024' => ['dbname' => 'archive'] + self::MYSQL,
        ])->detect(Component::System, 'mysql', 'MySQL', [], self::noCollector(), false, 3306);

        self::assertSame(['default', '2024'], self::ids($detection));
        self::assertSame(['shop', 'archive'], array_map(static fn(Instance $instance): ?string => $instance->detail, $detection->instances));
        self::assertTrue($detection->instances[1]->isFound());
        self::assertSame(Component::System, $detection->instances[1]->component);
        self::assertNull($detection->instances[0]->server);
    }

    public function testExcludedConnectionsAreNeverRead(): void
    {
        $reads = 0;
        $excluded = static function () use (&$reads): \ArrayObject {
            ++$reads;

            return new \ArrayObject(self::MYSQL);
        };
        $instances = self::instances(['default' => self::MYSQL, 's001' => self::MYSQL], ['s001_backup' => $excluded, 'legacy' => $excluded], ['*_backup', 'legacy']);

        self::assertSame(['default', 's001'], self::ids($instances->detect(Component::System, 'mysql', 'MySQL', [], self::noCollector(), false, 3306)));
        self::assertSame(0, $reads);
        self::assertSame(['default', 's001', 's001_backup', 'legacy'], self::ids(self::instances(['default' => self::MYSQL, 's001' => self::MYSQL], ['s001_backup' => $excluded, 'legacy' => $excluded])->detect(Component::System, 'mysql', 'MySQL', [], self::noCollector(), false, 3306)));
    }

    public function testAnUnreadableConnectionIsLeftAside(): void
    {
        $detection = self::instances(['default' => self::MYSQL], ['broken' => self::unreadable()])
            ->detect(Component::System, 'mysql', 'MySQL', [], self::noCollector(), false, 3306);

        self::assertSame(['default'], self::ids($detection));
    }

    public function testTheReasonWhenNothingIsFound(): void
    {
        $missing = 'The parameters of this connection cannot be read: an environment variable is missing ("NOPE_URL").';
        $onlyBroken = self::instances(['default' => self::SQLITE], ['broken' => self::unreadable()]);
        $many = self::instances([], array_fill_keys(['s1', 's2', 's3', 's4', 's5'], self::unreadable()));
        $excluded = self::instances(['default' => self::MYSQL], exclude: ['*']);
        $none = new DatabaseInstances(new DoctrineConnections(new ServiceLocator([]), []), new Container(), [], []);

        self::assertSame('No MySQL Doctrine connection found. Connection "broken" could not be read: ' . $missing, self::error($onlyBroken));
        self::assertSame(\sprintf('No MySQL Doctrine connection found. Connection "s1" could not be read: %1$s Connection "s2" could not be read: %1$s Connection "s3" could not be read: %1$s 2 more could not be read.', $missing), self::error($many));
        self::assertSame('No MySQL Doctrine connection found.', self::error($excluded));
        self::assertSame('No Doctrine DBAL connection is configured.', self::error($none));
    }

    public function testTwoConnectionsOnOneServerShareItsKey(): void
    {
        $servers = self::servers([
            'default' => ['host' => 'DB.internal', 'port' => 3306] + self::MYSQL,
            'reporting' => ['host' => 'db.internal', 'port' => '3306'] + self::MYSQL,
        ]);

        self::assertSame([['db.internal:3306', 'db.internal:3306'], ['db.internal:3306', 'db.internal:3306']], $servers);
    }

    public function testLocalhostAndItsAddressAreTwoServers(): void
    {
        $servers = self::servers([
            'default' => ['host' => 'localhost'] + self::MYSQL,
            'loopback' => ['host' => '127.0.0.1'] + self::MYSQL,
        ]);

        self::assertSame([['localhost:3306', 'localhost:3306'], ['127.0.0.1:3306', '127.0.0.1:3306']], $servers);
    }

    public function testASocketIsAServer(): void
    {
        $servers = self::servers([
            'default' => ['host' => 'localhost', 'unix_socket' => '/run/mysqld/mysqld.sock'] + self::MYSQL,
            'remote' => ['host' => 'db.internal', 'unix_socket' => '/run/mysqld/mysqld.sock'] + self::MYSQL,
        ]);

        self::assertSame([['socket:/run/mysqld/mysqld.sock', '/run/mysqld/mysqld.sock'], ['db.internal:3306', 'db.internal:3306']], $servers);
    }

    public function testTheServerHasItsDefaultPort(): void
    {
        $params = [
            'default' => self::MYSQL,
            'no_host' => ['host' => null] + self::MYSQL,
            'ipv6' => ['host' => '::1'] + self::MYSQL,
            'url' => ['url' => 'mysql://u:p@[::1]/app'] + self::MYSQL,
        ];

        self::assertSame([
            ['db.internal:3306', 'db.internal:3306'],
            ['localhost:3306', 'localhost:3306'],
            ['::1:3306', '[::1]:3306'],
            ['::1:3306', '[::1]:3306'],
        ], self::servers($params));
        self::assertSame(['db.internal:3307', 'db.internal:3307'], self::servers(['default' => self::MYSQL], 3307)[0]);
    }

    public function testDetectingAsksForNoConnection(): void
    {
        $given = [];
        $instances = new DatabaseInstances(
            new DoctrineConnections(new ServiceLocator(['default' => static fn(): \ArrayObject => new \ArrayObject(['dbname' => 'shop'] + self::MYSQL)]), ['default']),
            new Container(),
            ['default' => 'doctrine.dbal.default_connection'],
            [],
        );

        $detection = $instances->detect(Component::System, 'mysql', 'MySQL', [], static function (ConnectionTarget $target, DbalLink $link) use (&$given): array {
            $given[] = $link;

            return [];
        }, true, 3306);

        self::assertTrue($detection->instances[0]->isFound());
        self::assertCount(1, $given);
        $failure = Failures::thrownBy(static fn() => $given[0]->fetchAllAssociative('SELECT 1'));
        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertInstanceOf(ServiceNotFoundException::class, $failure->getPrevious());
    }

    public function testAConnectionWithoutAServiceIdIsUnreachable(): void
    {
        $namesake = new FakeDatabase();
        $container = new Container();
        $container->set('default', $namesake);
        $links = [];
        $instances = new DatabaseInstances(
            new DoctrineConnections(new ServiceLocator(['default' => static fn(): \ArrayObject => new \ArrayObject(self::MYSQL)]), ['default']),
            $container,
            [],
            [],
        );
        $instances->detect(Component::System, 'mysql', 'MySQL', [], static function (ConnectionTarget $target, DbalLink $link) use (&$links): array {
            $links[] = $link;

            return [];
        }, false, 3306);

        $failure = Failures::thrownBy(static fn() => $links[0]->fetchAllAssociative('SELECT 1'));

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame(0, $namesake->calls());
    }

    public function testTheSetReleasesWhatItsLinkOpened(): void
    {
        $connection = new FakeDatabase();
        $container = new Container();
        $container->set('doctrine.dbal.default_connection', $connection);

        self::readAndRelease(self::single($container));

        self::assertSame(1, $connection->closes);
    }

    public function testAConnectionOpenedByItsConstructionIsClosedOnlyWhenTheLinkBuiltIt(): void
    {
        $builtByTheLink = self::builder();
        self::readAndRelease(self::single($builtByTheLink));

        $builtByTheApplication = self::builder();
        $application = $builtByTheApplication->get('doctrine.dbal.default_connection');
        self::readAndRelease(self::single($builtByTheApplication));

        $closedByTheLink = $builtByTheLink->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(FakeDatabase::class, $closedByTheLink);
        self::assertSame(1, $closedByTheLink->closes);
        self::assertInstanceOf(FakeDatabase::class, $application);
        self::assertSame(0, $application->closes);
        self::assertTrue($application->isConnected());
    }

    public function testOnlyAConnectionThatIsNotPersistentGetsTheReadTimeout(): void
    {
        $initial = ini_get('mysqlnd.net_read_timeout');
        if ($initial === false) {
            self::markTestSkipped('mysqlnd is not loaded.');
        }
        ini_set('mysqlnd.net_read_timeout', '31');

        try {
            $container = new Container();
            $params = [];
            foreach (['plain' => [], 'persistent' => ['persistent' => true]] as $name => $option) {
                $container->set('doctrine.dbal.' . $name . '_connection', new FakeDatabase());
                $params[$name] = static fn(): \ArrayObject => new \ArrayObject($option + self::MYSQL);
            }
            $links = [];
            (new DatabaseInstances(
                new DoctrineConnections(new ServiceLocator($params), ['plain', 'persistent']),
                $container,
                ['plain' => 'doctrine.dbal.plain_connection', 'persistent' => 'doctrine.dbal.persistent_connection'],
                [],
            ))->detect(Component::System, 'mysql', 'MySQL', [], static function (ConnectionTarget $target, DbalLink $link) use (&$links): array {
                $links[$target->name] = $link;

                return [];
            }, false, 3306);

            $timeouts = [];
            foreach ($links as $name => $link) {
                $link->fetchAllAssociative('SELECT 1');
                $connection = $container->get('doctrine.dbal.' . $name . '_connection');
                self::assertInstanceOf(FakeDatabase::class, $connection);
                $timeouts[$name] = $connection->readTimeouts;
            }

            self::assertSame(['plain' => ['10'], 'persistent' => ['31']], $timeouts);
        } finally {
            ini_set('mysqlnd.net_read_timeout', $initial);
        }
    }

    private static function builder(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('doctrine.dbal.default_connection', (new Definition(FakeDatabase::class, [[], true]))->setPublic(true));
        $container->compile();

        return $container;
    }

    private static function single(Container $container): DatabaseInstances
    {
        return new DatabaseInstances(
            new DoctrineConnections(new ServiceLocator(['default' => static fn(): \ArrayObject => new \ArrayObject(self::MYSQL)]), ['default']),
            $container,
            ['default' => 'doctrine.dbal.default_connection'],
            [],
        );
    }

    private static function readAndRelease(DatabaseInstances $instances): void
    {
        $links = [];
        $set = $instances->detect(Component::System, 'mysql', 'MySQL', [], static function (ConnectionTarget $target, DbalLink $link) use (&$links): array {
            $links[] = $link;

            return [];
        }, false, 3306)->instances[0]->collectors;
        self::assertNotNull($set);

        $links[0]->fetchAllAssociative('SELECT 1');
        $set->release();
    }

    /**
     * @param array<string, array<string, mixed>> $params
     * @param array<string, \Closure>             $unreadable name => what reading the parameters does
     * @param list<string>                        $exclude
     */
    private static function instances(array $params, array $unreadable = [], array $exclude = []): DatabaseInstances
    {
        $factories = array_map(static fn(array $connection): \Closure => static fn(): \ArrayObject => new \ArrayObject($connection), $params) + $unreadable;

        return new DatabaseInstances(
            new DoctrineConnections(new ServiceLocator($factories), array_map(strval(...), array_keys($factories))),
            new Container(),
            array_map(static fn(int|string $name): string => 'doctrine.dbal.' . $name . '_connection', array_combine(array_keys($factories), array_keys($factories))),
            $exclude,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $params
     *
     * @return list<array{string, string}> the key and the address of the server of each instance
     */
    private static function servers(array $params, int $defaultPort = 3306): array
    {
        $detection = self::instances($params)->detect(Component::System, 'mysql', 'MySQL', [], self::noCollector(), true, $defaultPort);

        return array_map(static function (Instance $instance): array {
            self::assertNotNull($instance->server);

            return [$instance->server->key, $instance->server->address];
        }, $detection->instances);
    }

    private static function noCollector(): \Closure
    {
        return static fn(): array => [];
    }

    private static function unreadable(): \Closure
    {
        return static fn(): never => throw new EnvNotFoundException('Environment variable not found: "NOPE_URL".');
    }

    private static function error(DatabaseInstances $instances): ?string
    {
        $detection = $instances->detect(Component::System, 'mysql', 'MySQL', [], self::noCollector(), false, 3306);
        self::assertFalse($detection->isFound());

        return $detection->error;
    }

    /**
     * @return list<?string>
     */
    private static function ids(Detection $detection): array
    {
        return array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances);
    }
}
