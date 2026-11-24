<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RedisDetectorTest extends TestCase
{
    private const NONE = 'No Redis DSN found: set jmonitor.stack.redis.connections, use Redis for a cache pool or the sessions, or set the REDIS_URL environment variable.';

    public function testTheConfiguredConnectionsAreReadAloneAndInTheirOrder(): void
    {
        $detector = self::detector(
            ['sessions' => 'redis://sessions.internal:6380/1', 'main' => 'rediss://user:secret@cache.internal'],
            ['.cache_connection.a' => 'redis://pool.internal', RedisDsns::ENVIRONMENT => 'redis://env.internal'],
        );

        $detection = $detector->detect();

        self::assertSame(Component::Redis, $detector->component());
        self::assertSame(['sessions', 'main'], self::ids($detection));
        self::assertSame(['sessions.internal:6380', 'cache.internal:6379'], array_map(static fn(Instance $instance): ?string => $instance->server?->key, $detection->instances));
        foreach ($detection->instances as $instance) {
            self::assertSame(Component::Redis, $instance->component);
            self::assertTrue($instance->isFound());
            self::assertNull($instance->detail);
        }
    }

    public function testTheServersOfTheCacheAndOfTheSessionsAreFoundByTheirAddress(): void
    {
        $detection = self::detector([], [
            '.cache_connection.a' => 'redis://pool.internal:6390/2',
            'session.abstract_handler' => 'valkey://[::1]',
            RedisDsns::ENVIRONMENT => 'redis://env.internal',
        ])->detect();

        self::assertSame(['pool.internal:6390', '[::1]:6379'], self::ids($detection));
        self::assertSame(['pool.internal:6390', '::1:6379'], array_map(static fn(Instance $instance): ?string => $instance->server?->key, $detection->instances));
    }

    public function testRedisUrlIsOnlyReadWithoutAnyOtherRedis(): void
    {
        $detection = self::detector([], [
            '.cache_connection.a' => 'memcached://memcached.internal',
            RedisDsns::ENVIRONMENT => 'redis://env.internal',
        ])->detect();

        self::assertSame(['env.internal:6379'], self::ids($detection));
    }

    public function testWithoutAnyRedisItSaysWhereToSetOne(): void
    {
        $none = self::detector([], [])->detect();
        $unset = self::detector([], [RedisDsns::ENVIRONMENT => null])->detect();

        self::assertFalse($none->isFound());
        self::assertSame(self::NONE, $none->error);
        self::assertFalse($unset->isFound());
        self::assertSame(self::NONE, $unset->error);
    }

    public function testTwoDsnOfOneServerMakeOneInstance(): void
    {
        $found = self::detector([], ['.cache_connection.a' => 'redis://cache/0', 'session.abstract_handler' => 'redis://cache:6379/1', '.cache_connection.b' => 'redis://other'])->detect();
        $configured = self::detector(['cache' => 'redis://cache/0', 'sessions' => 'redis://user:pass@CACHE/1', 'other' => 'redis://other'], [])->detect();

        self::assertSame(['cache:6379', 'other:6379'], self::ids($found));
        self::assertSame(['cache', 'other'], self::ids($configured));
    }

    public function testAConfiguredDsnThatCannotBeReadIsShownWithItsReason(): void
    {
        $detection = self::detector([
            'memcached' => 'memcached://cache',
            'sentinel' => 'redis://cache?redis_sentinel=mymaster',
            'cut' => 'redis://app:pa/ss@cache',
            'missing' => new EnvNotFoundException('Environment variable not found: "SESSIONS_URL".'),
            'main' => 'redis://cache',
        ], [])->detect();

        self::assertSame(['memcached', 'sentinel', 'cut', 'missing', 'main'], self::ids($detection));
        self::assertSame([
            'This DSN is not a Redis DSN.',
            'A Redis Cluster, a Sentinel or several hosts are not read.',
            'The DSN of this connection cannot be read.',
            'The DSN of this connection cannot be read: an environment variable is missing ("SESSIONS_URL").',
            null,
        ], array_map(static fn(Instance $instance): ?string => $instance->error, $detection->instances));
        self::assertTrue($detection->instances[4]->isFound());
    }

    public function testAFoundDsnThatIsNotRedisIsLeftOut(): void
    {
        $detection = self::detector([], [
            '.cache_connection.a' => 'memcached://memcached.internal',
            '.cache_connection.b' => 'redis://cache?redis_cluster=1',
            'session.abstract_handler' => 'mysql://user:secret@db/sessions',
            '.cache_connection.c' => new EnvNotFoundException('Environment variable not found: "POOL_URL".'),
            '.cache_connection.d' => 'redis://cache.internal',
        ])->detect();

        self::assertSame(['cache.internal:6379'], self::ids($detection));
        self::assertSame([null], array_map(static fn(Instance $instance): ?string => $instance->error, $detection->instances));
    }

    /**
     * @param array<string, string|\Throwable|null> $configured
     * @param array<string, string|\Throwable|null> $found
     */
    private static function detector(array $configured, array $found): RedisDetector
    {
        return new RedisDetector(self::dsns($configured, $found));
    }

    /**
     * @param array<string, string|\Throwable|null> $configured
     * @param array<string, string|\Throwable|null> $found
     */
    private static function dsns(array $configured, array $found): RedisDsns
    {
        $entries = array_map(static fn(string|\Throwable|null $dsn): \Closure => static fn(): \ArrayObject => $dsn instanceof \Throwable ? throw $dsn : new \ArrayObject([$dsn]), $configured + $found);

        return new RedisDsns(new ServiceLocator($entries), array_map(strval(...), array_keys($configured)), array_map(strval(...), array_keys($found)));
    }

    /**
     * @return list<?string>
     */
    private static function ids(Detection $detection): array
    {
        return array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances);
    }
}
