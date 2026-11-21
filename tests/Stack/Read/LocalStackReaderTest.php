<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\BreakingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\Detectors;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\StaticDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\ThrowingDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ForgetfulCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RefusingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ThrowingCachePool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class LocalStackReaderTest extends TestCase
{
    private const START = 1_791_799_200;

    private CountingCachePool $cache;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->cache = new CountingCachePool();
        $this->clock = new MockClock('@' . self::START);
    }

    public function testTheOverviewReadsEveryDetectedComponentInOrder(): void
    {
        $journal = new \ArrayObject();
        $detector = fn(Component $component): StaticDetector => self::detector(
            $component,
            self::spec(new FakeCollector($component->value . '.slow', ['ok' => true], journal: $journal), cost: CostClass::Slow),
            self::spec(new FakeCollector($component->value . '.now', ['ok' => true], journal: $journal)),
        );
        $registry = Detectors::registry([
            'symfony' => $detector(Component::Symfony),
            'php' => $detector(Component::Php),
            'system' => $detector(Component::System),
        ], self::settings());
        $reader = $this->reader($registry);
        $this->warm();

        $state = $reader->overview(ReadMode::Fragment);

        self::assertSame([Component::System, Component::Php, Component::Symfony], array_map(static fn(ComponentState $component): Component => $component->component, $state->components));
        self::assertSame([], $state->notDetected);
        self::assertSame(['system.now', 'php.now', 'symfony.now', 'system.slow', 'php.slow', 'symfony.slow'], $journal->getArrayCopy(), 'one read: each cost class over the whole screen');
        self::assertSame(ResultState::Fresh, $state->component(Component::Php)?->instances[0]->snapshot?->result('php.now')?->state);
        self::assertSame(ResultState::Fresh, $state->component(Component::Symfony)?->instances[0]->snapshot?->result('symfony.slow')?->state);
        self::assertSame(0, $state->component(Component::System)->unread);
        self::assertSame(self::START, $state->readAt);
    }

    public function testTheOverviewReadsAtMostEightInstancesOfAComponent(): void
    {
        $collectors = [];
        $instances = [];

        for ($i = 1; $i <= 10; ++$i) {
            $collectors[] = $collector = new FakeCollector('php.status', ['n' => $i]);
            $instances[] = Instance::found(Component::Php, new CollectorSet(Component::Php, [self::spec($collector)]), 'pool-' . $i);
        }

        $registry = Detectors::registry(['php' => new StaticDetector(Component::Php, Detection::instances($instances))], self::settings());

        $php = $this->reader($registry)->overview(ReadMode::Page)->component(Component::Php);

        self::assertNotNull($php);
        self::assertSame(array_map(static fn(int $i): string => 'pool-' . $i, range(1, 10)), array_map(static fn(InstanceState $instance): ?string => $instance->id, $php->instances));
        self::assertSame(['n' => 8], $php->instances[7]->snapshot?->data('php.status'));
        self::assertNull($php->instances[8]->snapshot);
        self::assertNull($php->instances[9]->snapshot);
        self::assertSame(2, $php->unread);
        self::assertSame([1, 1, 1, 1, 1, 1, 1, 1, 0, 0], array_map(static fn(FakeCollector $collector): int => $collector->collects, $collectors));
    }

    public function testAComponentLeftToAutoAndNotFoundIsExplained(): void
    {
        $registry = Detectors::registry([
            'system' => self::detector(Component::System, self::spec(new FakeCollector('system.server'))),
            'php' => new StaticDetector(Component::Php, Detection::unavailable('No PHP binary here.')),
        ], self::settings());

        $state = $this->reader($registry)->overview(ReadMode::Page);

        self::assertSame(['php' => 'No PHP binary here.', 'symfony' => Instance::UNAVAILABLE], $state->notDetected);
        self::assertNull($state->component(Component::Php));
        self::assertNull($state->component(Component::Symfony));
        self::assertNotNull($state->component(Component::System));
    }

    public function testADisabledComponentIsNowhere(): void
    {
        $php = self::detector(Component::Php, self::spec(new FakeCollector('php.status')));
        $registry = Detectors::registry([
            'system' => self::detector(Component::System, self::spec(new FakeCollector('system.server'))),
            'php' => $php,
        ], self::settings(['php' => false, 'symfony' => false]));

        $state = $this->reader($registry)->overview(ReadMode::Page);

        self::assertSame([Component::System], array_map(static fn(ComponentState $component): Component => $component->component, $state->components));
        self::assertSame([], $state->notDetected);
        self::assertSame(0, $php->calls);
    }

    public function testAForcedComponentThatIsNotFoundIsListedWithItsReason(): void
    {
        $registry = Detectors::registry([
            'php' => new StaticDetector(Component::Php, Detection::unavailable('No PHP binary here.')),
        ], self::settings(['php' => true, 'system' => false, 'symfony' => false]));

        $state = $this->reader($registry)->overview(ReadMode::Page);

        $php = $state->component(Component::Php);
        self::assertNotNull($php);
        self::assertCount(1, $php->instances);
        self::assertSame('No PHP binary here.', $php->instances[0]->error);
        self::assertSame('No PHP binary here.', $php->instances[0]->snapshot?->error);
        self::assertSame([], $state->notDetected);
    }

    public function testReadingAComponentOnlyDetectsThatComponent(): void
    {
        $system = self::detector(Component::System, self::spec(new FakeCollector('system.server')));
        $php = self::detector(Component::Php, self::spec(new FakeCollector('php.status')));
        $symfony = self::detector(Component::Symfony, self::spec(new FakeCollector('symfony.kernel')));
        $registry = Detectors::registry(['system' => $system, 'php' => $php, 'symfony' => $symfony], self::settings());

        $state = $this->reader($registry)->component(Component::Php, null, ReadMode::Page);

        self::assertSame([Component::Php], array_map(static fn(ComponentState $component): Component => $component->component, $state->components));
        self::assertSame([0, 1, 0], [$system->calls, $php->calls, $symfony->calls]);
    }

    public function testReadingAComponentOnlyCollectsTheAskedInstance(): void
    {
        [$registry, $collectors] = $this->threePools();
        $reader = $this->reader($registry);

        $php = $reader->component(Component::Php, 'b', ReadMode::Page)->component(Component::Php);

        self::assertNotNull($php);
        self::assertSame(['a', 'b', 'c'], array_map(static fn(InstanceState $instance): ?string => $instance->id, $php->instances));
        self::assertSame([null, ['pool' => 'b'], null], array_map(static fn(InstanceState $instance): ?array => $instance->snapshot?->data('php.status'), $php->instances));
        self::assertSame(2, $php->unread);
        self::assertSame([0, 1, 0], array_map(static fn(FakeCollector $collector): int => $collector->collects, $collectors));

        $first = $reader->component(Component::Php, null, ReadMode::Page)->component(Component::Php);

        self::assertSame(['pool' => 'a'], $first?->instances[0]->snapshot?->data('php.status'));
        self::assertSame([1, 1, 0], array_map(static fn(FakeCollector $collector): int => $collector->collects, $collectors));
    }

    public function testAnUnknownInstanceGivesNoComponent(): void
    {
        [$registry, $collectors] = $this->threePools(['system' => 'auto', 'symfony' => false]);
        $reader = $this->reader($registry);

        $unknown = $reader->component(Component::Php, 'z', ReadMode::Page);
        $undetected = $reader->component(Component::System, null, ReadMode::Page);
        $disabled = $reader->component(Component::Symfony, null, ReadMode::Page);

        self::assertSame([], $unknown->components);
        self::assertSame([], $unknown->notDetected);
        self::assertSame([0, 0, 0], array_map(static fn(FakeCollector $collector): int => $collector->collects, $collectors));
        self::assertSame([], $undetected->components);
        self::assertSame(['system' => Instance::UNAVAILABLE], $undetected->notDetected);
        self::assertSame([], $disabled->components);
        self::assertSame([], $disabled->notDetected);
    }

    public function testAPageRunsNoSlowCollectorAndAFragmentDoes(): void
    {
        $now = new FakeCollector('php.status');
        $network = new FakeCollector('php.remote');
        $slow = new FakeCollector('php.recipes');
        $registry = Detectors::registry(['php' => self::detector(
            Component::Php,
            self::spec($now),
            self::spec($network, cost: CostClass::Network),
            self::spec($slow, cost: CostClass::Slow),
        )], self::settings());
        $reader = $this->reader($registry);
        $this->warm();

        $page = $reader->overview(ReadMode::Page);

        self::assertSame([1, 1, 0], [$now->collects, $network->collects, $slow->collects]);
        self::assertSame(ResultState::Pending, $page->component(Component::Php)?->instances[0]->snapshot?->result('php.recipes')?->state);

        $reader->component(Component::Php, null, ReadMode::Page);

        self::assertSame(0, $slow->collects);

        $fragment = $reader->overview(ReadMode::Fragment);

        self::assertSame([1, 1, 1], [$now->collects, $network->collects, $slow->collects]);
        self::assertSame(ResultState::Fresh, $fragment->component(Component::Php)?->instances[0]->snapshot?->result('php.recipes')?->state);
    }

    public function testTheStateSaysWhenSomethingIsStillToRead(): void
    {
        $detector = fn(Component $component): StaticDetector => self::detector(
            $component,
            self::spec(new FakeCollector($component->value . '.now')),
            self::spec(new FakeCollector($component->value . '.remote'), cost: CostClass::Network),
        );
        $registry = Detectors::registry(['system' => $detector(Component::System), 'php' => $detector(Component::Php)], self::settings());
        $reader = $this->reader($registry);

        $cold = $reader->overview(ReadMode::Page);

        self::assertSame(CacheState::Cold, $cold->cache);
        self::assertTrue($cold->isReading());
        self::assertSame(ResultState::Pending, $cold->component(Component::System)?->instances[0]->snapshot?->result('system.remote')?->state);
        self::assertSame(ResultState::Pending, $cold->component(Component::Php)?->instances[0]->snapshot?->result('php.remote')?->state, 'one probe for the whole screen');

        $fragment = $reader->overview(ReadMode::Fragment);

        self::assertSame(CacheState::Working, $fragment->cache);
        self::assertFalse($fragment->isReading());
    }

    public function testRefreshForgetsWhatTheScreenShows(): void
    {
        $collectors = [];
        $registry = Detectors::registry(array_map(function (Component $component) use (&$collectors): StaticDetector {
            $collectors[] = $now = new FakeCollector($component->value . '.now');
            $collectors[] = $remote = new FakeCollector($component->value . '.remote');

            return self::detector($component, self::spec($now), self::spec($remote, cost: CostClass::Network));
        }, ['system' => Component::System, 'php' => Component::Php]), self::settings());
        $reader = $this->reader($registry);
        $this->warm();
        $reader->overview(ReadMode::Fragment);

        self::assertTrue($reader->refresh());

        $reader->overview(ReadMode::Fragment);

        self::assertSame([2, 2, 2, 2], array_map(static fn(FakeCollector $collector): int => $collector->collects, $collectors));
    }

    public function testASecondRefreshWithinTenSecondsForgetsNothing(): void
    {
        $status = new FakeCollector('php.status');
        $reader = $this->reader(Detectors::registry(['php' => self::detector(Component::Php, self::spec($status))], self::settings()));
        $this->warm();
        $reader->overview(ReadMode::Page);

        self::assertTrue($reader->refresh());
        $reader->overview(ReadMode::Page);
        self::assertSame(2, $status->collects);

        $this->clock->sleep(9);

        self::assertFalse($reader->refresh());
        $reader->overview(ReadMode::Page);
        self::assertSame(2, $status->collects);

        self::assertTrue($reader->refresh(Component::Php), 'another scope');
        $reader->overview(ReadMode::Page);
        self::assertSame(3, $status->collects);

        $this->clock->sleep(1);

        self::assertTrue($reader->refresh());
        $reader->overview(ReadMode::Page);
        self::assertSame(4, $status->collects);
    }

    public function testRefreshingAComponentLeavesTheOthers(): void
    {
        $server = new FakeCollector('system.server');
        $status = new FakeCollector('php.status');
        $registry = Detectors::registry([
            'system' => self::detector(Component::System, self::spec($server)),
            'php' => self::detector(Component::Php, self::spec($status)),
        ], self::settings());
        $reader = $this->reader($registry);
        $this->warm();
        $reader->overview(ReadMode::Page);

        self::assertTrue($reader->refresh(Component::Php));

        $reader->overview(ReadMode::Page);

        self::assertSame([1, 2], [$server->collects, $status->collects]);
    }

    public function testRefreshingAComponentForgetsEachOfItsInstances(): void
    {
        $collectors = [];
        $instances = [];

        for ($i = 1; $i <= 10; ++$i) {
            $collectors[] = $collector = new FakeCollector('php.status', ['n' => $i]);
            $instances[] = Instance::found(Component::Php, new CollectorSet(Component::Php, [self::spec($collector)]), 'pool-' . $i);
        }

        $reader = $this->reader(Detectors::registry(['php' => new StaticDetector(Component::Php, Detection::instances($instances))], self::settings()));
        $this->warm();
        $reader->component(Component::Php, 'pool-10', ReadMode::Page);

        self::assertTrue($reader->refresh(Component::Php));

        $reader->component(Component::Php, 'pool-10', ReadMode::Page);

        self::assertSame(2, $collectors[9]->collects);
    }

    public function testARefreshKeepsEndOfLife(): void
    {
        $status = new FakeCollector('php.status');
        $endOfLife = new FakeCollector('eol.php', ['cycles' => []]);
        $registry = Detectors::registry(['php' => self::detector(
            Component::Php,
            self::spec($status),
            self::spec($endOfLife, Lifetime::Day, CostClass::Slow),
        )], self::settings());
        $reader = $this->reader($registry);
        $this->warm();
        $reader->overview(ReadMode::Fragment);

        self::assertTrue($reader->refresh());

        $state = $reader->overview(ReadMode::Fragment);

        self::assertSame([2, 1], [$status->collects, $endOfLife->collects]);
        self::assertSame(ResultState::Fresh, $state->component(Component::Php)?->instances[0]->snapshot?->result('eol.php')?->state);
    }

    /**
     * @return iterable<string, array{CacheItemPoolInterface}>
     */
    public static function poolsOutOfOrder(): iterable
    {
        yield 'array' => [new ArrayAdapter()];
        yield 'keeps nothing' => [new ForgetfulCachePool()];
        yield 'down' => [new ThrowingCachePool()];
    }

    #[DataProvider('poolsOutOfOrder')]
    public function testARefreshWithoutWorkingCacheIsRefused(CacheItemPoolInterface $pool): void
    {
        $status = new FakeCollector('php.status');
        $reader = $this->reader(Detectors::registry(['php' => self::detector(Component::Php, self::spec($status))], self::settings()), $pool);

        self::assertFalse($reader->refresh());
        self::assertFalse($reader->refresh(Component::Php));

        $state = $reader->overview(ReadMode::Page);

        self::assertSame(CacheState::OutOfOrder, $state->cache);
        self::assertSame(ResultState::Fresh, $state->component(Component::Php)?->instances[0]->snapshot?->result('php.status')?->state);

        if ($pool instanceof ArrayAdapter) {
            self::assertSame([], $pool->getValues(), 'nothing is written');
        }
    }

    public function testARefreshThatCannotBeKeptForgetsNothing(): void
    {
        $status = new FakeCollector('php.status');
        $registry = Detectors::registry(['php' => self::detector(Component::Php, self::spec($status))], self::settings());
        $inner = new ArrayAdapter();
        $broken = false;
        $breaking = new BreakingCachePool(static function (string $method) use (&$broken): bool {
            return $broken && $method === 'save';
        }, $inner);
        $logger = new BufferingLogger();
        $reader = $this->reader($registry, $breaking, $logger);
        $reader->overview(ReadMode::Page);
        $reader->overview(ReadMode::Page);
        $broken = true;

        self::assertFalse($reader->refresh());
        self::assertCount(1, $logger->cleanLogs());

        self::assertFalse($this->reader($registry, new RefusingCachePool(inner: $inner))->refresh(), 'a key the cache refuses would not hold the next refresh off');

        $broken = false;
        $reader->overview(ReadMode::Page);

        self::assertSame(1, $status->collects);
    }

    public function testTheStateNamesTheWebServer(): void
    {
        $registry = Detectors::registry(['php' => self::detector(Component::Php, self::spec(new FakeCollector('php.status')))], self::settings());

        $state = $this->reader($registry, node: 'web-2')->overview(ReadMode::Page);

        self::assertSame('web-2', $state->node);
        self::assertSame('web-2', $state->component(Component::Php)?->instances[0]->snapshot?->node);
        self::assertNull($this->reader($registry, node: '')->overview(ReadMode::Page)->node);
    }

    public function testTheStateIsPlainData(): void
    {
        $status = new FakeCollector('php.status', ['requests' => 100]);
        $instances = [Instance::unavailable(Component::System, 'Not mounted.', 'disk-0', '/mnt/data')];

        for ($i = 1; $i <= 8; ++$i) {
            $instances[] = Instance::found(Component::System, new CollectorSet(Component::System, [self::spec(new FakeCollector('system.server', ['load' => [1.5, 0.5]]))]), 'node-' . $i, 'Node ' . $i);
        }

        $registry = Detectors::registry([
            'system' => new StaticDetector(Component::System, Detection::instances($instances)),
            'php' => self::detector(
                Component::Php,
                self::spec($status, Lifetime::Dynamic, trackDelta: true),
                self::spec(new FakeCollector('php.remote', collectFailure: new \RuntimeException('Timed out.')), cost: CostClass::Network),
            ),
        ], self::settings());
        $reader = $this->reader($registry);
        $reader->overview(ReadMode::Fragment);
        $this->clock->sleep(11);
        $status->setData(['requests' => 150]);

        $state = $reader->overview(ReadMode::Fragment);

        self::assertSame(50, $state->component(Component::Php)?->instances[0]->snapshot?->delta('php.status')?->getValue('requests'));
        self::assertSame(ResultState::Failed, $state->component(Component::Php)->instances[0]->snapshot->result('php.remote')?->state);
        self::assertSame(1, $state->component(Component::System)?->unread);
        self::assertSame(['symfony' => Instance::UNAVAILABLE], $state->notDetected);

        $serialized = serialize($state);

        self::assertEquals($state, unserialize($serialized));
        self::assertStringNotContainsString('Fixtures', $serialized, 'no collector');
        self::assertStringNotContainsString('Detection', $serialized, 'no instance');
    }

    public function testNothingGetsOut(): void
    {
        $detectors = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException('The detectors cannot be listed.');
            }

            public function has(string $id): bool
            {
                throw new \LogicException('The detectors cannot be listed.');
            }
        };
        $logger = new BufferingLogger();
        $reader = $this->reader(new ComponentRegistry($detectors, self::settings()), logger: $logger);
        $this->warm();

        $overview = $reader->overview(ReadMode::Fragment);
        $component = $reader->component(Component::Php, null, ReadMode::Page);
        $refreshed = $reader->refresh();

        foreach ([$overview, $component] as $state) {
            self::assertSame([], $state->components);
            self::assertSame([], $state->notDetected);
            self::assertSame('web-1', $state->node);
        }

        self::assertFalse($refreshed);
        self::assertSame(['error', 'error', 'error'], array_map(static fn(array $log): string => $log[0], $logger->cleanLogs()));
        self::assertFalse($this->cache->hasItem('jmonitor.stack.refreshed.all'));
    }

    public function testReasonsCarryNoCredentials(): void
    {
        $registry = Detectors::registry([
            'php' => new ThrowingDetector(Component::Php, 'Cannot reach redis://app:s3cret@cache'),
            'symfony' => new ThrowingDetector(Component::Symfony, 'Cannot reach redis://app:s3cret@cache'),
        ], self::settings(['system' => false, 'symfony' => true]));

        $state = $this->reader($registry)->overview(ReadMode::Page);

        $reasons = [$state->notDetected['php'] ?? '', $state->component(Component::Symfony)?->instances[0]->error ?? ''];
        self::assertSame(['Cannot reach redis://cache', 'Cannot reach redis://cache'], $reasons);
        self::assertStringNotContainsString('s3cret', serialize($state));
        self::assertStringNotContainsString('app:', serialize($state));
    }

    /**
     * @param array<string, 'auto'|bool> $components
     *
     * @return array{ComponentRegistry, list<FakeCollector>}
     */
    private function threePools(array $components = []): array
    {
        $collectors = [];
        $instances = [];

        foreach (['a', 'b', 'c'] as $id) {
            $collectors[] = $collector = new FakeCollector('php.status', ['pool' => $id]);
            $instances[] = Instance::found(Component::Php, new CollectorSet(Component::Php, [self::spec($collector)]), $id);
        }

        return [Detectors::registry([
            'php' => new StaticDetector(Component::Php, Detection::instances($instances)),
            'symfony' => self::detector(Component::Symfony, self::spec(new FakeCollector('symfony.kernel'))),
        ], self::settings($components)), $collectors];
    }

    /**
     * @param array<string, 'auto'|bool> $overrides
     *
     * @return array<string, array{enabled: 'auto'|bool}>
     */
    private static function settings(array $overrides = []): array
    {
        $settings = [];

        foreach (Component::cases() as $component) {
            $settings[$component->value] = ['enabled' => $overrides[$component->value] ?? 'auto'];
        }

        return $settings;
    }

    private static function detector(Component $component, CollectorSpec ...$specs): StaticDetector
    {
        return new StaticDetector($component, Detection::found(new CollectorSet($component, array_values($specs))));
    }

    private static function spec(FakeCollector $collector, Lifetime $lifetime = Lifetime::Static, CostClass $cost = CostClass::Immediate, bool $trackDelta = false): CollectorSpec
    {
        return new CollectorSpec($collector, $lifetime, $cost, $trackDelta);
    }

    /**
     * The next probe finds the cache working, as after a first display.
     */
    private function warm(): void
    {
        (new CacheProbe($this->cache, $this->clock))->state();
    }

    private function reader(ComponentRegistry $registry, ?CacheItemPoolInterface $cache = null, ?LoggerInterface $logger = null, string $node = 'web-1'): LocalStackReader
    {
        $cache ??= $this->cache;
        $webNode = new WebNode($node);
        $snapshots = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10), $cache, $this->clock, $webNode);

        return new LocalStackReader($registry, $snapshots, new CacheProbe($cache, $this->clock), $cache, $this->clock, $webNode, $logger);
    }
}
