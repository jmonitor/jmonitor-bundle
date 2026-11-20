<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\DependencyInjection\CachePoolPass;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Recording\Cache\RecordingAdapter;
use Jmonitor\JmonitorBundle\Recording\Cache\RecordingTagAwareAdapter;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\CacheConsumer;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Cache\DataCollector\CacheDataCollector;
use Symfony\Component\Cache\Messenger\EarlyExpirationDispatcher;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class CachePoolPassTest extends KernelTestCase
{
    /** @var list<string> */
    private static array $varDirs = [];

    private string $bufferDir;

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-cache-pool-pass-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testTheApplicationPoolAndTheDeclaredPoolsAreWrapped(): void
    {
        self::bootKernel(['jmonitor' => $this->recording()]);
        $pools = $this->consumer();

        static::assertInstanceOf(RecordingAdapter::class, static::getContainer()->get('cache.app'));
        foreach (['cache', 'items', 'appPlain', 'appPlainItems', 'appTaggedUntagged', 'appDerived'] as $property) {
            static::assertInstanceOf(RecordingAdapter::class, $pools->{$property}, $property);
            static::assertNotInstanceOf(RecordingTagAwareAdapter::class, $pools->{$property}, $property);
        }
        foreach (['taggable', 'appPlainTaggable', 'appTagged'] as $property) {
            static::assertInstanceOf(RecordingTagAwareAdapter::class, $pools->{$property}, $property);
        }
        static::assertContains('cache', $this->screens());
    }

    public function testTheWrapperOffersEveryInterfaceOfItsPool(): void
    {
        self::bootKernel(['jmonitor' => $this->recording()]);
        $pools = $this->consumer();
        $interfaces = array_filter(
            [AdapterInterface::class, CacheInterface::class, PruneableInterface::class, ResettableInterface::class, NamespacedPoolInterface::class],
            'interface_exists',
        );

        foreach (['cache.app' => $pools->cache, 'app.plain' => $pools->appPlain, 'app.tagged' => $pools->appTagged] as $name => $wrapper) {
            static::assertInstanceOf(RecordingAdapter::class, $wrapper);
            $pool = $wrapper->getPool();
            static::assertNotInstanceOf(RecordingAdapter::class, $pool, $name);
            foreach ($interfaces as $interface) {
                if ($pool instanceof $interface) {
                    static::assertInstanceOf($interface, $wrapper, $name . ' ' . $interface);
                }
            }
        }

        static::assertInstanceOf(TagAwareAdapterInterface::class, $pools->appTagged);
        static::assertInstanceOf(TagAwareCacheInterface::class, $pools->appTagged);
    }

    public function testThePoolsBuiltOnTheSystemAdapterAndThoseOfSymfonyAreLeftAlone(): void
    {
        self::bootKernel(['jmonitor' => $this->recording()]);
        $pools = $this->consumer();

        foreach (['appSystem' => $pools->appSystem, 'cache.system' => $pools->system, 'cache.validator' => $pools->validator] as $name => $pool) {
            static::assertNotInstanceOf(RecordingAdapter::class, $pool, $name);
        }
    }

    public function testAPoolBuiltOnTheApplicationPoolIsWrappedOnce(): void
    {
        self::bootKernel(['jmonitor' => $this->recording()]);

        foreach (['app.derived' => $this->consumer()->appDerived, 'cache.app' => $this->consumer()->cache] as $name => $pool) {
            $wrappers = 0;
            while ($pool instanceof TraceableAdapter) {
                $wrappers += (int) ($pool instanceof RecordingAdapter);
                $pool = $pool->getPool();
            }
            static::assertSame(1, $wrappers, $name);
        }
    }

    public function testAReadThroughATagAwareViewCountsOnceOnItsPool(): void
    {
        self::bootKernel(['jmonitor' => $this->recording()]);
        $pools = $this->consumer();
        $recorder = static::getContainer()->get(Recorder::class);
        \assert($recorder instanceof Recorder);

        $unit = $recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $tagged = static function (ItemInterface $item): string {
            $item->tag('tag');

            return 'value';
        };
        $pools->taggable->get('item', $tagged);
        $pools->taggable->get('item', $tagged);
        $pools->appTagged->get('item', $tagged);
        $pools->appTaggedUntagged->get('untagged', static fn(): string => 'value');
        $recorder->end($unit, 'GET app_test', 1.0);

        static::assertSame([
            'app.tagged' => ['mi' => 2, 'w' => 2],
            'cache.app' => ['mi' => 1, 'w' => 1, 'h' => 1],
        ], $this->recordedCounts());
    }

    public function testAPoolThatComputesEarlyIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('jmonitor.recording.cache', true);
        $container->setParameter(CachePoolPass::DECLARED_POOLS, ['app.early', 'app.early_traced', 'app.plain']);
        $container->register(Recorder::class, Recorder::class);
        $early = static fn(string $id): Definition => (new Definition(ArrayAdapter::class))->addMethodCall('setCallbackWrapper', [
            new Definition(EarlyExpirationDispatcher::class, [
                new Reference('bus'),
                new Reference('reverse_container'),
                (new Definition('callable'))->setFactory([new Reference($id), 'setCallbackWrapper'])->addArgument(null),
            ]),
        ]);
        $container->setDefinition('app.early', $early('app.early'))->addTag('cache.pool');
        $container->setDefinition('app.early_traced.recorder_inner', $early('app.early_traced.recorder_inner'));
        $container->register('app.early_traced', TraceableAdapter::class)
            ->setArguments([new Reference('app.early_traced.recorder_inner')])
            ->addTag('cache.pool');
        $container->register('app.plain', ArrayAdapter::class)->addTag('cache.pool');

        (new CachePoolPass())->process($container);

        static::assertSame(ArrayAdapter::class, $container->getDefinition('app.early')->getClass());
        static::assertSame(TraceableAdapter::class, $container->getDefinition('app.early_traced')->getClass());
        static::assertSame(RecordingAdapter::class, $container->getDefinition('app.plain')->getClass());
        static::assertSame(['cache.pool'], array_keys($container->getDefinition('app.plain')->getTags()));
        static::assertSame([], $container->getDefinition('app.plain.jmonitor_inner')->getTags());
    }

    public function testAPoolThatIsNotASymfonyAdapterIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('jmonitor.recording.cache', true);
        $container->setParameter(CachePoolPass::DECLARED_POOLS, ['app.psr6', 'app.decorated', 'app.plain']);
        $container->register(Recorder::class, Recorder::class)->setSynthetic(true)->setPublic(true);
        $container->register('app.psr6', Psr6Pool::class)
            ->setArguments([new Definition(ArrayAdapter::class)])
            ->addTag('cache.pool')
            ->setPublic(true);
        $container->register('app.decorated', ArrayAdapter::class)->addTag('cache.pool')->setPublic(true);
        $container->register('app.decorated.psr6', Psr6Pool::class)
            ->setDecoratedService('app.decorated')
            ->setArguments([new Reference('.inner')]);
        $container->register('app.plain', ArrayAdapter::class)->addTag('cache.pool')->setPublic(true);
        $container->addCompilerPass(new CachePoolPass(), PassConfig::TYPE_BEFORE_REMOVING, -1);
        $container->compile();
        $container->set(Recorder::class, new Recorder($this->createStub(BufferInterface::class)));

        static::assertInstanceOf(Psr6Pool::class, $container->get('app.psr6'));
        static::assertInstanceOf(Psr6Pool::class, $container->get('app.decorated'));
        static::assertInstanceOf(RecordingAdapter::class, $container->get('app.plain'));
    }

    public function testTurningTheCaptureOffWrapsNothing(): void
    {
        self::bootKernel(['jmonitor' => ['recording' => ['cache' => false] + $this->recording()['recording']]]);
        $pools = $this->consumer();

        foreach (['cache', 'items', 'taggable', 'appPlain', 'appTagged', 'appTaggedUntagged', 'appDerived'] as $property) {
            static::assertNotInstanceOf(RecordingAdapter::class, $pools->{$property}, $property);
        }
        static::assertNotContains('cache', $this->screens());
    }

    public function testTheCacheCommandsStillReachTheWrappedPools(): void
    {
        $kernel = self::bootKernel(['jmonitor' => $this->recording()]);
        $pool = $this->consumer()->appPlainItems;
        $pool->save($pool->getItem('kept')->set('value'));
        $application = new Application($kernel);

        $clear = new CommandTester($application->find('cache:pool:clear'));
        static::assertSame(0, $clear->execute(['pools' => ['app.plain']]), $clear->getDisplay());
        static::assertFalse($pool->getItem('kept')->isHit());

        $prune = new CommandTester($application->find('cache:pool:prune'));
        static::assertSame(0, $prune->execute([]), $prune->getDisplay());
        static::assertStringContainsString('cache.app', $prune->getDisplay());
    }

    public function testTheProfilerStillTracesThePoolsInDebugMode(): void
    {
        self::bootKernel(['jmonitor' => $this->recording(), 'profiler' => true]);
        $pools = $this->consumer();

        $pools->cache->get('profiled', static fn(): string => 'value');
        $pools->taggable->get('profiled-with-tags', static fn(): string => 'value');

        static::assertInstanceOf(RecordingAdapter::class, $pools->cache);
        static::assertInstanceOf(TraceableAdapter::class, $pools->cache->getPool());
        static::assertNotInstanceOf(RecordingAdapter::class, $pools->cache->getPool());
        $collector = static::getContainer()->get('data_collector.cache');
        static::assertInstanceOf(CacheDataCollector::class, $collector);
        $collector->lateCollect();
        static::assertGreaterThanOrEqual(2, $collector->getStatistics()['cache.app']['calls'] ?? 0);
    }

    /** @return array<string, array<string, int|float|string>> pool => counters, from the buffer */
    private function recordedCounts(): array
    {
        $directory = static::getContainer()->get(BufferDirectory::class);
        \assert($directory instanceof BufferDirectory);

        $counts = [];
        foreach (glob($directory->path() . '/*.ndjson') ?: [] as $file) {
            foreach (file($file, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                foreach (Entry::fromLine($line)->children ?? [] as $child) {
                    if ($child->type === Entry::TYPE_CACHE) {
                        $counts[$child->key] = array_diff_key($child->attributes, ['n' => true]);
                    }
                }
            }
        }
        ksort($counts);

        return $counts;
    }

    private function consumer(): CacheConsumer
    {
        $consumer = static::getContainer()->get(CacheConsumer::class);
        static::assertInstanceOf(CacheConsumer::class, $consumer);

        return $consumer;
    }

    /** @return list<string> */
    private function screens(): array
    {
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);

        return array_map(static fn(ScreenInterface $screen): string => $screen->id(), $registry->available());
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, profiler?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, $options['jmonitor'] ?? [], withProfiler: $options['profiler'] ?? false);
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

final class Psr6Pool implements CacheItemPoolInterface
{
    public function __construct(private readonly CacheItemPoolInterface $pool) {}

    public function getItem(string $key): CacheItemInterface
    {
        return $this->pool->getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        return $this->pool->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->pool->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->pool->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->pool->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->pool->commit();
    }
}
