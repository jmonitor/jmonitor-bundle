<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\RedisInfoCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\RedisLink;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RedisLiveTest extends TestCase
{
    private string $url;

    protected function setUp(): void
    {
        $url = getenv('JMONITOR_TEST_REDIS_URL');

        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('JMONITOR_TEST_REDIS_URL is not set.');
        }

        $this->url = $url;
    }

    /**
     * @return iterable<string, array{?class-string}>
     */
    public static function clients(): iterable
    {
        yield 'redis extension' => [null];
        yield 'Predis' => [\Predis\Client::class];
    }

    #[DataProvider('clients')]
    public function testInfoIsRead(?string $client): void
    {
        $link = self::link($this->url, $client);

        $kept = (new RedisInfoCollector($link))->collect();
        $link->release();

        self::assertNotNull($kept['server']['version']);
        self::assertNotNull($kept['memory']['used']);
        self::assertNotNull($kept['stats']['keyspace_hits']);
        self::assertArrayNotHasKey('config', $kept);
    }

    #[DataProvider('clients')]
    public function testTheConnectionIsClosedAfterTheRead(?string $client): void
    {
        $before = $this->connectedClients($client);
        $link = self::link($this->url, $client);

        $link->info();
        $during = $this->connectedClients($client);
        $link->release();
        $after = $this->connectedClients($client);

        self::assertSame($before + 1, $during);
        self::assertSame($before, $after);
    }

    #[DataProvider('clients')]
    public function testAClosedPortFailsAtOnce(?string $client): void
    {
        $link = self::link('redis://127.0.0.1:1', $client);

        $started = microtime(true);
        $failure = Failures::thrownBy(static fn() => $link->info());

        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertInstanceOf(UnreachableServerException::class, $failure);
    }

    /**
     * The other clients of the server, without the connection that counts them.
     */
    private function connectedClients(?string $client): int
    {
        $link = self::link($this->url, $client);

        try {
            return (int) $link->info()['connected_clients'] - 1;
        } finally {
            $link->release();
        }
    }

    /**
     * @param ?class-string $client
     */
    private static function link(string $url, ?string $client): RedisLink
    {
        if ($client === null && !\extension_loaded('redis')) {
            self::markTestSkipped('The redis extension is not loaded.');
        }

        if ($client !== null && !class_exists($client)) {
            self::markTestSkipped('predis/predis is not installed.');
        }

        $dsns = new RedisDsns(
            new ServiceLocator(['main' => static fn(): \ArrayObject => new \ArrayObject([$url])]),
            ['main'],
            [],
            $client === null ? null : static fn(string $dsn, array $options): object => RedisAdapter::createConnection($dsn, ['class' => $client] + $options),
        );

        return $dsns->link('main');
    }
}
