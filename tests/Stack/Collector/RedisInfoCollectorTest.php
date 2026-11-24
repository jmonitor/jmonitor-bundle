<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\RedisInfoCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\RedisLink;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeRedis;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RedisPayloads;
use PHPUnit\Framework\TestCase;

final class RedisInfoCollectorTest extends TestCase
{
    public function testItKeepsWhatTheLibraryReads(): void
    {
        $collector = self::collector(RedisPayloads::info());

        self::assertSame('redis', $collector->getName());
        self::assertSame(RedisPayloads::kept(), $collector->collect());
    }

    public function testTheSectionsOfPredisAreKeptAlike(): void
    {
        $kept = self::collector(RedisPayloads::sections())->collect();

        self::assertSame(array_keys(RedisPayloads::kept()), array_keys($kept));
        self::assertSame('7.2.4', $kept['server']['version']);
        self::assertSame('1500000000', $kept['memory']['used']);
        self::assertSame(['keys' => '1500', 'expires' => '1200', 'avg_ttl' => '3600000'], $kept['databases']['db0']);
    }

    public function testTheNameAndTheVersionOfValkeyAreKept(): void
    {
        $server = self::collector(RedisPayloads::valkey())->collect()['server'];

        self::assertSame('7.2.4', $server['version']);
        self::assertSame('valkey', $server['name']);
        self::assertSame('8.0.1', $server['valkey_version']);
    }

    public function testTheModeOfValkeyIsKeptUnderTheSameKey(): void
    {
        $info = RedisPayloads::valkey();
        unset($info['redis_mode']);
        $info['server_mode'] = 'cluster';

        self::assertSame('cluster', self::collector($info)->collect()['server']['mode']);
        self::assertSame('standalone', self::collector(RedisPayloads::info())->collect()['server']['mode']);
    }

    public function testTheConfigOfTheLibraryIsNotKept(): void
    {
        $redis = new FakeRedis(RedisPayloads::info());

        $kept = (new RedisInfoCollector(new RedisLink(static fn(): FakeRedis => $redis)))->collect();

        self::assertArrayNotHasKey('config', $kept);
        self::assertSame(0, $redis->configs);
    }

    public function testNoPathOfTheServerIsKept(): void
    {
        $kept = serialize(self::collector(RedisPayloads::info())->collect());

        self::assertStringNotContainsString('/data/redis-server', $kept);
        self::assertStringNotContainsString('/etc/redis', $kept);
    }

    public function testAnUnreachableServerStaysUnreachable(): void
    {
        $failure = Failures::thrownBy(static fn() => self::collector(new \RuntimeException('Redis connection failed: Connection refused'))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
    }

    private static function collector(mixed $answer): RedisInfoCollector
    {
        $redis = new FakeRedis($answer);

        return new RedisInfoCollector(new RedisLink(static fn(): FakeRedis => $redis));
    }
}
