<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Recording\Cache\RecordingAdapter;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class StackCachePoolTest extends WebTestCase
{
    use PollsThePage;

    private const POOL = 'jmonitor.stack.cache';

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-stack-cache-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testThePoolIsNotWrappedByTheCacheRecording(): void
    {
        self::bootKernel(['jmonitor' => $this->recording()]);

        static::assertInstanceOf(RecordingAdapter::class, static::getContainer()->get('cache.app'));
        static::assertSame(0, self::recordingWrappers(self::pool()));

        self::ensureKernelShutdown();
        self::bootKernel(['jmonitor' => $this->recording(), 'profiler' => true]);

        static::assertInstanceOf(RecordingAdapter::class, static::getContainer()->get('cache.app'));
        static::assertInstanceOf(TraceableAdapter::class, self::pool());
        static::assertSame(0, self::recordingWrappers(self::pool()));
    }

    public function testItsReadsAndWritesDoNotShowOnTheCacheScreen(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['flush_interval' => 1] + $this->recording()['recording']]]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/stack-cached');
            static::assertResponseIsSuccessful();
        }
        $crawler = self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-cache tr[data-jm-pool="cache.app"][data-jm-reads="3"]');

        static::assertResponseIsSuccessful();
        $rows = $crawler->filter('section#jm-cache tbody tr');
        static::assertSame(['cache.app'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-pool')));
        static::assertSame(['3'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-reads')));
    }

    public function testItFollowsTheAdapterOfTheApplicationPool(): void
    {
        self::bootKernel(['jmonitor' => $this->recording(), 'cacheApp' => 'cache.adapter.array']);

        static::assertInstanceOf(ArrayAdapter::class, self::pool());

        self::ensureKernelShutdown();
        self::bootKernel(['jmonitor' => $this->recording()]);

        static::assertSame(self::untraced(static::getContainer()->get('cache.app'))::class, self::untraced(self::pool())::class);
    }

    public function testItsKeysAreItsOwn(): void
    {
        self::bootKernel(['jmonitor' => $this->recording()]);
        $application = self::application();
        $stack = self::pool();

        $stack->save($stack->getItem('stack-only')->set('value'));
        $application->save($application->getItem('app-only')->set('value'));

        static::assertFalse($application->hasItem('stack-only'));
        static::assertFalse($stack->hasItem('app-only'));
        static::assertTrue($stack->hasItem('stack-only'));
        static::assertTrue($application->hasItem('app-only'));
    }

    public function testItIsClearedOnItsOwn(): void
    {
        $kernel = self::bootKernel(['jmonitor' => $this->recording()]);
        $application = self::application();
        $stack = self::pool();
        $stack->save($stack->getItem('stack-kept')->set('value'));
        $application->save($application->getItem('app-kept')->set('value'));

        $clear = new CommandTester((new Application($kernel))->find('cache:pool:clear'));

        static::assertSame(0, $clear->execute(['pools' => [self::POOL]]), $clear->getDisplay());
        static::assertFalse($stack->getItem('stack-kept')->isHit());
        static::assertTrue($application->getItem('app-kept')->isHit());
    }

    private static function pool(): CacheItemPoolInterface
    {
        $pool = static::getContainer()->get(self::POOL);
        static::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    private static function application(): CacheItemPoolInterface
    {
        $pool = static::getContainer()->get('cache.app');
        static::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    private static function recordingWrappers(object $pool): int
    {
        $wrappers = 0;
        while ($pool instanceof TraceableAdapter) {
            $wrappers += (int) ($pool instanceof RecordingAdapter);
            $pool = $pool->getPool();
        }

        return $wrappers;
    }

    private static function untraced(?object $pool): ?object
    {
        while ($pool instanceof TraceableAdapter) {
            $pool = $pool->getPool();
        }

        return $pool;
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, profiler?: bool, cacheApp?: string} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            'test',
            true,
            $options['jmonitor'] ?? [],
            withProfiler: $options['profiler'] ?? false,
            withPublicInternals: true,
            cacheApp: $options['cacheApp'] ?? null,
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @return array{recording: array<string, mixed>}
     */
    private function recording(): array
    {
        return ['recording' => ['enabled' => true, 'buffer_dir' => $this->bufferDir]];
    }
}
