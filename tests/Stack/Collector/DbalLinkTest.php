<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\ConnectionProvider;
use PHPUnit\Framework\TestCase;

final class DbalLinkTest extends TestCase
{
    private const READ_TIMEOUT = 'mysqlnd.net_read_timeout';

    public function testTheConnectionIsOnlyAskedForAtTheFirstQuery(): void
    {
        $asked = 0;
        $connection = self::mysql();
        $link = new DbalLink(static function () use (&$asked, $connection): Connection {
            ++$asked;

            return $connection;
        });

        self::assertSame(0, $asked);
        self::assertEquals([['one' => 1]], $link->fetchAllAssociative('SELECT 1 AS one'));
        self::assertEquals([['two' => 2]], $link->fetchAllAssociative('SELECT ? AS two', [2]));
        self::assertSame(1, $asked);
    }

    public function testAConnectionItOpenedIsClosedOnRelease(): void
    {
        $connection = self::mysql();
        $link = self::link($connection);

        $link->fetchAllAssociative('SELECT 1');
        self::assertTrue($connection->isConnected());
        $link->release();

        self::assertFalse($connection->isConnected());
    }

    public function testAConnectionAlreadyOpenIsLeftOpen(): void
    {
        $connection = self::mysql();
        $connection->fetchOne('SELECT 1');
        $link = self::link($connection);

        $link->fetchAllAssociative('SELECT 2');
        $link->release();

        self::assertTrue($connection->isConnected());
    }

    public function testTheApplicationCanUseTheConnectionAgainAfterTheRelease(): void
    {
        $connection = self::mysql();
        $link = self::link($connection);

        $link->fetchAllAssociative('SELECT 1');
        $link->release();

        self::assertEquals(2, $connection->fetchOne('SELECT 2'));
    }

    public function testAClosedPortIsAnUnreachableServer(): void
    {
        $connection = DriverManager::getConnection(ConnectionProvider::parse('mysql://jmonitor:jmonitor@127.0.0.1:1/jmonitor'));

        $failure = Failures::thrownBy(static fn() => self::link($connection)->fetchAllAssociative('SELECT 1'));

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertInstanceOf(ConnectionException::class, $failure->getPrevious());
        self::assertSame($failure->getPrevious()->getMessage(), $failure->getMessage());
        self::assertFalse($connection->isConnected());
    }

    public function testAfterAnUnreachableServerEveryQueryFailsAtOnceWithTheSameException(): void
    {
        $asked = 0;
        $connection = new FakeDatabase(['SHOW' => new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused')]);
        $link = new DbalLink(static function () use (&$asked, $connection): Connection {
            ++$asked;

            return $connection;
        });

        $first = Failures::thrownBy(static fn() => $link->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'"));
        $second = Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1'));

        self::assertInstanceOf(UnreachableServerException::class, $first);
        self::assertSame($first, $second);
        self::assertSame(1, $connection->calls());
        self::assertSame(1, $asked);
    }

    public function testARefusedPasswordIsUnreachableToo(): void
    {
        $params = ['password' => 'not-the-password'] + ConnectionProvider::parse(self::mysqlUrl());
        $connection = DriverManager::getConnection($params);

        $failure = Failures::thrownBy(static fn() => self::link($connection)->fetchAllAssociative('SELECT 1'));

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertStringNotContainsString('not-the-password', $failure->getMessage());
    }

    public function testADeniedQueryLeavesTheLinkUsable(): void
    {
        $denied = new ConnectionException(new class ("SELECT command denied to user 'jmonitor'@'localhost' for table 'events_statements_summary_by_digest'", '42000', 1142) extends AbstractException {}, null);
        $connection = new FakeDatabase(['performance_schema' => $denied, 'version' => [['Value' => '8.4.0']]], connected: true);
        $link = self::link($connection);

        self::assertSame($denied, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT DIGEST_TEXT FROM performance_schema.events_statements_summary_by_digest')));
        self::assertSame([['Value' => '8.4.0']], $link->fetchAllAssociative("SHOW GLOBAL VARIABLES LIKE 'version'"));
        self::assertSame(0, $connection->closes);
    }

    public function testAQueryOnAMissingTableLeavesTheLinkUsable(): void
    {
        $link = self::link(self::mysql());

        self::assertInstanceOf(TableNotFoundException::class, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT * FROM jmonitor_no_such_table')));
        self::assertEquals([['one' => 1]], $link->fetchAllAssociative('SELECT 1 AS one'));
    }

    public function testAConnectionThatCannotBeBuiltIsUnreachable(): void
    {
        $asked = 0;
        $cause = new \RuntimeException('An exception occurred while establishing a connection to figure out your platform version.');
        $link = new DbalLink(static function () use (&$asked, $cause): Connection {
            ++$asked;

            throw $cause;
        });

        $first = Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1'));

        self::assertInstanceOf(UnreachableServerException::class, $first);
        self::assertSame($cause, $first->getPrevious());
        self::assertSame($first, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 2')));
        self::assertSame(1, $asked);
        $link->release();
    }

    public function testAConnectionLostWhileOpenIsRetriedOnce(): void
    {
        $open = new FakeDatabase(['SHOW' => [['Value' => '42']]], connected: true);
        $open->failNext(self::connectionLost());

        self::assertSame([['Value' => '42']], self::link($open)->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'"));
        self::assertSame(2, $open->calls());

        $lostTwice = new FakeDatabase(connected: true);
        $lostTwice->failNext(self::connectionLost())->failNext($second = self::connectionLost());

        self::assertSame($second, Failures::thrownBy(static fn() => self::link($lostTwice)->fetchAllAssociative('SELECT 1')));
        self::assertSame(2, $lostTwice->calls());

        $opening = new FakeDatabase();
        $opening->failNext(self::connectionLost());

        self::assertInstanceOf(UnreachableServerException::class, Failures::thrownBy(static fn() => self::link($opening)->fetchAllAssociative('SELECT 1')));
        self::assertSame(1, $opening->calls());
    }

    public function testALostConnectionThatStillSaysConnectedIsClosedAndUnreachable(): void
    {
        $connection = new FakeDatabase(['SHOW' => new \RuntimeException('SQLSTATE[08006] [7] no connection to the server')], connected: true);
        $link = self::link($connection);

        $first = Failures::thrownBy(static fn() => $link->fetchAllAssociative("SHOW GLOBAL STATUS LIKE 'Uptime'"));

        self::assertInstanceOf(UnreachableServerException::class, $first);
        self::assertSame(1, $connection->closes);
        self::assertSame($first, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1')));
        self::assertSame(1, $connection->calls());
    }

    public function testThePrimaryIsRead(): void
    {
        $params = ConnectionProvider::parse(self::mysqlUrl());
        $server = array_diff_key($params, ['driver' => true]);
        $connection = DriverManager::getConnection([
            'wrapperClass' => PrimaryReadReplicaConnection::class,
            'driver' => $params['driver'],
            'primary' => $server,
            'replica' => [$server],
        ]);
        self::assertInstanceOf(PrimaryReadReplicaConnection::class, $connection);
        $connection->ensureConnectedToReplica();
        $link = self::link($connection);

        $link->fetchAllAssociative('SELECT 1');

        self::assertTrue($connection->isConnectedToPrimary());
    }

    public function testTheReadTimeoutIsSetWhileOpeningAndPutBack(): void
    {
        $initial = ini_get(self::READ_TIMEOUT);
        if ($initial === false) {
            self::markTestSkipped('mysqlnd is not loaded.');
        }
        ini_set(self::READ_TIMEOUT, '31');

        try {
            $connection = new FakeDatabase();
            $link = self::link($connection);
            $link->fetchAllAssociative('SELECT 1');
            $link->fetchAllAssociative('SELECT 2');

            $failing = new FakeDatabase(['SELECT' => new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused')]);
            Failures::thrownBy(static fn() => self::link($failing)->fetchAllAssociative('SELECT 1'));

            self::assertSame(['10', '31'], $connection->readTimeouts);
            self::assertSame(['10'], $failing->readTimeouts);
            self::assertSame('31', ini_get(self::READ_TIMEOUT));
        } finally {
            ini_set(self::READ_TIMEOUT, $initial);
        }
    }

    private static function link(Connection $connection): DbalLink
    {
        return new DbalLink(static fn(): Connection => $connection);
    }

    private static function mysql(): Connection
    {
        return DriverManager::getConnection(ConnectionProvider::parse(self::mysqlUrl()));
    }

    private static function mysqlUrl(): string
    {
        $url = getenv('JMONITOR_TEST_MYSQL_URL');
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('JMONITOR_TEST_MYSQL_URL is not set.');
        }

        return $url;
    }

    private static function connectionLost(): ConnectionLost
    {
        return new ConnectionLost(new class ('MySQL server has gone away', 'HY000', 2006) extends AbstractException {}, null);
    }
}
