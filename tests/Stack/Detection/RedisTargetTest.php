<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Detection\RedisTarget;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string, ?int, ?string, string, string}>
     */
    public static function readable(): iterable
    {
        yield 'host alone' => ['redis://localhost', 'redis', 'localhost', null, null, 'localhost:6379', 'localhost:6379'];
        yield 'credentials, port, database and options' => ['rediss://user:secret@cache.internal:6380/2?timeout=5', 'rediss', 'cache.internal', 6380, null, 'cache.internal:6380', 'cache.internal:6380'];
        yield 'password alone' => ['redis://secret@cache', 'redis', 'cache', null, null, 'cache:6379', 'cache:6379'];
        yield 'empty user' => ['redis://:secret@cache:6390', 'redis', 'cache', 6390, null, 'cache:6390', 'cache:6390'];
        yield 'socket' => ['redis:///var/run/redis.sock', 'redis', null, null, '/var/run/redis.sock', 'socket:/var/run/redis.sock', '/var/run/redis.sock'];
        yield 'socket with a password and a database' => ['redis://secret@/var/run/redis.sock/3', 'redis', null, null, '/var/run/redis.sock', 'socket:/var/run/redis.sock', '/var/run/redis.sock'];
        yield 'valkey' => ['valkey://cache', 'valkey', 'cache', null, null, 'cache:6379', 'cache:6379'];
        yield 'valkeys' => ['valkeys://cache:6390', 'valkeys', 'cache', 6390, null, 'cache:6390', 'cache:6390'];
        yield 'IPv6' => ['redis://[::1]:6379', 'redis', '::1', 6379, null, '::1:6379', '[::1]:6379'];
        yield 'host in capitals' => ['redis://Cache.Internal', 'redis', 'Cache.Internal', null, null, 'cache.internal:6379', 'cache.internal:6379'];
        yield 'socket without slashes' => ['redis:/var/run/redis.sock', 'redis', null, null, '/var/run/redis.sock', 'socket:/var/run/redis.sock', '/var/run/redis.sock'];
    }

    #[DataProvider('readable')]
    public function testAReadableDsnGivesItsServer(string $dsn, string $scheme, ?string $host, ?int $port, ?string $socket, string $key, string $address): void
    {
        $target = RedisTarget::of($dsn);

        self::assertSame([$scheme, $host, $port, $socket], [$target->scheme, $target->host, $target->port, $target->socket]);
        self::assertSame([$key, $address], [$target->server()->key, $target->server()->address]);
    }

    public function testNoCredentialIsKept(): void
    {
        foreach (['rediss://user:secret@cache.internal:6380/2?timeout=5', 'redis://secret@cache', 'redis://user:secret@/var/run/redis.sock'] as $dsn) {
            $kept = serialize(RedisTarget::of($dsn)) . var_export(RedisTarget::of($dsn), true);

            self::assertStringNotContainsString('user', $kept, $dsn);
            self::assertStringNotContainsString('secret', $kept, $dsn);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'memcached' => ['memcached://cache', 'This DSN is not a Redis DSN.'];
        yield 'empty' => ['', 'This DSN is not a Redis DSN.'];
        yield 'scheme in capitals' => ['REDIS://cache', 'This DSN is not a Redis DSN.'];
        yield 'several hosts' => ['redis:?host[a]&host[b]', 'A Redis Cluster, a Sentinel or several hosts are not read.'];
        yield 'one host in the query' => ['redis:?host[cache:6379]', 'A Redis Cluster, a Sentinel or several hosts are not read.'];
        yield 'sentinel' => ['redis://cache?redis_sentinel=mymaster', 'A Redis Cluster, a Sentinel or several hosts are not read.'];
        yield 'sentinel, short name' => ['redis://cache?sentinel=mymaster', 'A Redis Cluster, a Sentinel or several hosts are not read.'];
        yield 'cluster' => ['redis://cache?redis_cluster=1', 'A Redis Cluster, a Sentinel or several hosts are not read.'];
        yield 'cluster, short name' => ['redis://cache?cluster=redis', 'A Redis Cluster, a Sentinel or several hosts are not read.'];
        yield 'password not encoded' => ['redis://app:pa/ss@cache', 'The DSN of this connection cannot be read.'];
        yield 'password with a question mark' => ['redis://app:pa?ss@cache', 'The DSN of this connection cannot be read.'];
        yield 'password with a hash' => ['redis://app:pa#ss@cache', 'The DSN of this connection cannot be read.'];
        yield 'no host' => ['redis://', 'The DSN of this connection cannot be read.'];
        yield 'port that is not a number' => ['redis://cache:port', 'The DSN of this connection cannot be read.'];
    }

    #[DataProvider('refused')]
    public function testADsnThatCannotBeReadIsRefusedWithItsReason(string $dsn, string $reason): void
    {
        $failure = Failures::thrownBy(static fn() => RedisTarget::of($dsn));

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame($reason, $failure->getMessage());
        self::assertNull($failure->getPrevious());
    }

    public function testNoReasonCarriesAnyPartOfTheDsn(): void
    {
        foreach (['memcached://jm-user:jm-secret@jm-host:11211', 'redis:?host[jm-host:6379]&host[jm-other:6379]&auth=jm-secret', 'redis://jm-user:jm/secret@jm-host:6379', 'redis://jm-secret@jm-host?redis_sentinel=jm-master', 'redis://jm-host:jm-port'] as $dsn) {
            $reason = Failures::thrownBy(static fn() => RedisTarget::of($dsn))->getMessage();

            self::assertDoesNotMatchRegularExpression('/jm-|11211|6379/', $reason, $dsn);
        }
    }
}
