<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\RedisInfoCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeRedis;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RedisPayloads;
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

    public function testARedisFoundButNotReadSaysWhy(): void
    {
        $cluster = self::detector([], ['.cache_connection.a' => 'redis://jm-user:jm-secret@jm-host:6390?redis_cluster=1', RedisDsns::ENVIRONMENT => null])->detect();
        $slash = self::detector([], ['session.abstract_handler' => 'redis://jm-user:jm/secret@jm-host:6390', '.cache_connection.a' => 'memcached://cache'])->detect();

        self::assertFalse($cluster->isFound());
        self::assertSame('Redis is used by the application, but not read here: A Redis Cluster, a Sentinel or several hosts are not read. Name a server to read under jmonitor.stack.redis.connections.', $cluster->error);
        self::assertFalse($slash->isFound());
        self::assertSame('Redis is used by the application, but not read here: The DSN of this connection cannot be read. Name a server to read under jmonitor.stack.redis.connections.', $slash->error);
    }

    public function testWhatIsNotRedisStaysSilent(): void
    {
        $detection = self::detector([], [
            '.cache_connection.a' => 'memcached://memcached.internal',
            'session.abstract_handler' => 'mysql://user:secret@db/sessions',
            '.cache_connection.b' => '',
            '.cache_connection.c' => new EnvNotFoundException('Environment variable not found: "POOL_URL".'),
            RedisDsns::ENVIRONMENT => null,
        ])->detect();

        self::assertSame(self::NONE, $detection->error);
    }

    public function testEachServerIsReadByOneCollectorAndTheSupportOfBothProducts(): void
    {
        $endOfLife = new FakeUrlFetcher(null);
        $detection = (new RedisDetector(self::dsns(['main' => 'redis://cache'], []), true, $endOfLife))->detect();

        $instance = $detection->instances[0];
        self::assertNotNull($instance->collectors);
        self::assertSame(['redis', 'eol.redis', 'eol.valkey'], $instance->collectors->names());
        $flags = [];
        foreach ($instance->collectors->specs() as $spec) {
            $flags[$spec->name] = [$spec->collector::class, $spec->lifetime, $spec->cost, $spec->trackDelta, $spec->shared, $spec->detailOnly, $spec->perServer];
        }
        self::assertSame([
            'redis' => [RedisInfoCollector::class, Lifetime::Dynamic, CostClass::Network, true, false, false, false],
            'eol.redis' => [EndOfLifeCollector::class, Lifetime::Day, CostClass::Slow, false, true, false, false],
            'eol.valkey' => [EndOfLifeCollector::class, Lifetime::Day, CostClass::Slow, false, true, false, false],
        ], $flags);
        self::assertSame(0, $endOfLife->calls);
    }

    public function testWithoutEndOfLifeThereIsNoSupportLookup(): void
    {
        self::assertSame(['redis'], self::detector(['main' => 'redis://cache'], [])->detect()->instances[0]->collectors?->names());
    }

    public function testDetectingOpensNoConnection(): void
    {
        $connects = 0;
        $detector = new RedisDetector(self::dsns(['main' => 'redis://cache', 'other' => 'redis://other'], [], $connects), true, new FakeUrlFetcher(null));

        $detection = $detector->detect();

        self::assertCount(2, $detection->instances);
        self::assertSame(0, $connects);
    }

    public function testEachServerReadsThroughItsOwnLinkAndClosesIt(): void
    {
        $connects = 0;
        $detection = (new RedisDetector(self::dsns([], ['.cache_connection.a' => 'redis://cache', 'session.abstract_handler' => 'redis://sessions'], $connects), false))->detect();

        foreach ($detection->instances as $instance) {
            self::assertNotNull($instance->collectors);
            $kept = $instance->collectors->get('redis')?->collector->collect();
            $instance->collectors->release();

            self::assertSame('7.2.4', $kept['server']['version'] ?? null);
        }
        self::assertSame(2, $connects);
    }

    /**
     * @param array<string, string|\Throwable|null> $configured
     * @param array<string, string|\Throwable|null> $found
     */
    private static function detector(array $configured, array $found): RedisDetector
    {
        return new RedisDetector(self::dsns($configured, $found), false);
    }

    /**
     * @param array<string, string|\Throwable|null> $configured
     * @param array<string, string|\Throwable|null> $found
     */
    private static function dsns(array $configured, array $found, int &$connects = 0): RedisDsns
    {
        $entries = array_map(static fn(string|\Throwable|null $dsn): \Closure => static fn(): \ArrayObject => $dsn instanceof \Throwable ? throw $dsn : new \ArrayObject([$dsn]), $configured + $found);

        return new RedisDsns(
            new ServiceLocator($entries),
            array_map(strval(...), array_keys($configured)),
            array_map(strval(...), array_keys($found)),
            static function () use (&$connects): FakeRedis {
                ++$connects;

                return new FakeRedis(RedisPayloads::info());
            },
        );
    }

    /**
     * @return list<?string>
     */
    private static function ids(Detection $detection): array
    {
        return array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances);
    }
}
