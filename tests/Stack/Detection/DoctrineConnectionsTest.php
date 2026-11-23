<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MysqlDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PostgresqlDriver;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SqliteDriver;
use Doctrine\DBAL\Tools\DsnParser;
use Jmonitor\JmonitorBundle\Stack\Detection\ConnectionTarget;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class DoctrineConnectionsTest extends TestCase
{
    private const DEFAULTS = ['driver' => 'pdo_mysql', 'host' => 'localhost', 'port' => null, 'user' => 'root', 'password' => null];

    public function testADiscreteConnectionIsRead(): void
    {
        $connections = self::connections(['default' => ['host' => 'db.internal', 'port' => 3307, 'dbname' => 'shop', 'password' => 's3cr3t'] + self::DEFAULTS]);

        self::assertSame(['default'], $connections->names());
        self::assertEquals(new ConnectionTarget('default', 'mysql', 'db.internal', 3307, 'shop', null), $connections->target('default'));
    }

    public function testTheUrlWinsOverTheDefaultsDoctrineAlwaysSets(): void
    {
        $connections = self::connections(['default' => ['url' => 'postgresql://u:p@db:5433/app'] + self::DEFAULTS]);

        self::assertEquals(new ConnectionTarget('default', 'postgresql', 'db', 5433, 'app', null), $connections->target('default'));
    }

    public function testTheSchemesOfTheApplicationAreKnown(): void
    {
        $params = ['default' => ['url' => 'maria://u:p@db/app'] + self::DEFAULTS];

        self::assertSame('mysql', self::connections($params, new DsnParser(['maria' => 'pdo_mysql']))->target('default')->driver);
        self::assertNull(self::connections($params)->target('default')->driver);
    }

    public function testADriverClassTellsTheDriver(): void
    {
        $connections = self::connections([
            'mysql' => ['driverClass' => MysqlDriver::class] + self::DEFAULTS,
            'postgresql' => ['driverClass' => PostgresqlDriver::class] + self::DEFAULTS,
            'sqlite' => ['driverClass' => SqliteDriver::class] + self::DEFAULTS,
            'url' => ['driverClass' => SqliteDriver::class, 'url' => 'mysql://u:p@db/app'] + self::DEFAULTS,
        ]);

        self::assertSame('mysql', $connections->target('mysql')->driver);
        self::assertSame('postgresql', $connections->target('postgresql')->driver);
        self::assertNull($connections->target('sqlite')->driver);
        self::assertSame('mysql', $connections->target('url')->driver);
    }

    public function testThePrimaryOfAReplicatedConnectionIsRead(): void
    {
        $replicas = ['replica1' => ['host' => 'db2.internal', 'dbname' => 'replica', 'user' => 'root', 'password' => 'p']];
        $connections = self::connections([
            'discrete' => [
                'driver' => 'pdo_pgsql',
                'wrapperClass' => PrimaryReadReplicaConnection::class,
                'primary' => ['host' => 'db1.internal', 'port' => 5433, 'dbname' => 'shop', 'user' => 'root', 'password' => 'p'],
                'replica' => $replicas,
            ],
            'url' => [
                'driver' => 'pdo_mysql',
                'wrapperClass' => PrimaryReadReplicaConnection::class,
                'primary' => ['url' => 'postgresql://u:p@db3.internal:5434/orders', 'host' => 'localhost', 'user' => 'root'],
                'replica' => $replicas,
            ],
        ]);

        self::assertEquals(new ConnectionTarget('discrete', 'postgresql', 'db1.internal', 5433, 'shop', null), $connections->target('discrete'));
        self::assertEquals(new ConnectionTarget('url', 'postgresql', 'db3.internal', 5434, 'orders', null), $connections->target('url'));
    }

    public function testTheSuffixIsAddedToTheDatabase(): void
    {
        $connections = self::connections([
            'discrete' => ['dbname' => 'app', 'dbname_suffix' => '_test'] + self::DEFAULTS,
            'url' => ['url' => 'mysql://u:p@db/app', 'dbname_suffix' => '_test3'] + self::DEFAULTS,
            'primary' => self::DEFAULTS + ['primary' => ['dbname' => 'shop', 'dbname_suffix' => '_test', 'host' => 'db']],
        ]);

        self::assertSame('app_test', $connections->target('discrete')->database);
        self::assertSame('app_test3', $connections->target('url')->database);
        self::assertSame('shop_test', $connections->target('primary')->database);
    }

    public function testASocketIsOnlyReadWithoutARealHost(): void
    {
        $socket = ['unix_socket' => '/run/mysqld/mysqld.sock'];
        $connections = self::connections([
            'localhost' => $socket + self::DEFAULTS,
            'capitals' => $socket + ['host' => 'LocalHost'] + self::DEFAULTS,
            'none' => $socket + ['host' => null] + self::DEFAULTS,
            'empty' => $socket + ['host' => ''] + self::DEFAULTS,
            'remote' => $socket + ['host' => 'db.internal'] + self::DEFAULTS,
        ]);

        foreach (['localhost', 'capitals', 'none', 'empty'] as $name) {
            self::assertSame('/run/mysqld/mysqld.sock', $connections->target($name)->socket, $name);
        }
        self::assertNull($connections->target('remote')->socket);
    }

    public function testAPortFromAnEnvironmentVariableIsAnInteger(): void
    {
        self::assertSame(3307, self::connections(['default' => ['port' => '3307'] + self::DEFAULTS])->target('default')->port);
    }

    public function testAnotherDriverHasNoDriver(): void
    {
        $target = self::connections(['default' => ['driver' => 'pdo_sqlite', 'path' => '/srv/app/var/data.sqlite'] + self::DEFAULTS])->target('default');

        self::assertNull($target->driver);
        self::assertNull($target->database);
    }

    public function testNoCredentialIsKept(): void
    {
        $connections = self::connections([
            'discrete' => ['dbname' => 'app', 'password' => 's3cr3t'] + self::DEFAULTS,
            'url' => ['url' => 'mysql://admin:s3cr3t@db/app'] + self::DEFAULTS,
        ]);

        foreach (['discrete', 'url'] as $name) {
            $kept = serialize($connections->target($name)) . var_export($connections->target($name), true);

            self::assertStringNotContainsString('root', $kept, $name);
            self::assertStringNotContainsString('admin', $kept, $name);
            self::assertStringNotContainsString('s3cr3t', $kept, $name);
        }
    }

    public function testAPasswordThatSlippedIntoTheDatabaseIsRefused(): void
    {
        $connections = self::connections([
            'slash' => ['url' => 'mysql://jmonitor:12/s3cr3t@127.0.0.1:36999/jmonitor'] + self::DEFAULTS,
            'question_mark' => ['url' => 'mysql://admin:12?s3cr3t@db.internal/app'] + self::DEFAULTS,
            'hash' => ['url' => 'mysql://admin:12#s3cr3t@db.internal/app'] + self::DEFAULTS,
        ]);

        foreach ($connections->names() as $name) {
            $failure = Failures::thrownBy(static fn() => $connections->target($name));

            self::assertInstanceOf(\RuntimeException::class, $failure, $name);
            self::assertSame('The URL of this connection cannot be read.', $failure->getMessage(), $name);
            self::assertNull($failure->getPrevious(), $name);
        }
    }

    public function testADiscreteDatabaseIsTakenAsItIs(): void
    {
        $connections = self::connections(['oracle' => ['driver' => 'oci8', 'dbname' => '//ora.internal:1521/XE'] + self::DEFAULTS]);

        self::assertSame('//ora.internal:1521/XE', $connections->target('oracle')->database);
    }

    public function testAMissingEnvironmentVariableNamesItAndNothingElse(): void
    {
        $connections = new DoctrineConnections(new ServiceLocator([
            'default' => static fn() => throw new EnvNotFoundException('Environment variable not found: "NOPE_URL".'),
        ]), ['default']);

        $failure = Failures::thrownBy(static fn() => $connections->target('default'));

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('The parameters of this connection cannot be read: an environment variable is missing ("NOPE_URL").', $failure->getMessage());
    }

    public function testAMalformedUrlGivesNoValueAway(): void
    {
        $connections = self::connections(['default' => ['url' => 'mysql://jmonitor:s3cr3t@db:99999/app'] + self::DEFAULTS]);

        $failure = Failures::thrownBy(static fn() => $connections->target('default'));

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('The parameters of this connection cannot be read: its URL is malformed.', $failure->getMessage());
        self::assertNull($failure->getPrevious());
    }

    public function testAResultIsReadOncePerRequestAndForgottenOnReset(): void
    {
        $reads = ['default' => 0, 'broken' => 0];
        $connections = new DoctrineConnections(new ServiceLocator([
            'default' => static function () use (&$reads): \ArrayObject {
                ++$reads['default'];

                return new \ArrayObject(['host' => 'db.internal'] + self::DEFAULTS);
            },
            'broken' => static function () use (&$reads): never {
                ++$reads['broken'];

                throw new EnvNotFoundException('Environment variable not found: "NOPE_URL".');
            },
        ]), ['default', 'broken']);

        $target = $connections->target('default');
        $failure = Failures::thrownBy(static fn() => $connections->target('broken'));

        self::assertSame($target, $connections->target('default'));
        self::assertSame($failure, Failures::thrownBy(static fn() => $connections->target('broken')));
        self::assertSame(['default' => 1, 'broken' => 1], $reads);

        $connections->reset();
        $connections->target('default');
        Failures::thrownBy(static fn() => $connections->target('broken'));

        self::assertSame(['default' => 2, 'broken' => 2], $reads);
    }

    /**
     * @param array<string, array<string, mixed>> $params
     */
    private static function connections(array $params, ?DsnParser $parser = null): DoctrineConnections
    {
        return new DoctrineConnections(
            new ServiceLocator(array_map(static fn(array $connection): \Closure => static fn(): \ArrayObject => new \ArrayObject($connection), $params)),
            array_keys($params),
            $parser,
        );
    }
}
