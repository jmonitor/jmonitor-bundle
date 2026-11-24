<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack;

use Jmonitor\JmonitorBundle\Dashboard\ActionGuard;
use Jmonitor\JmonitorBundle\Dashboard\Assets;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\Screen\MessagesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\NginxStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpCli;
use Jmonitor\JmonitorBundle\Stack\Collector\QueuesCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\MysqlDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\PostgresqlDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\QueuesInstance;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\QueuesBlocks;
use Jmonitor\JmonitorBundle\Stack\Screen\StackScreen;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\PhpRequest;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FakeHostUrls;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeRedis;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeStatusPages;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\Detectors;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\StaticDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ForgetfulCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\FreezableCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ThrowingCachePool;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Viewers of the stack within one lifetime of its values cost the monitored servers what one viewer costs.
 */
final class DisplayCostTest extends TestCase
{
    private const START = 1_791_799_200;
    private const DISPLAYS = 50;
    private const BETWEEN_DISPLAYS = 0.2;
    private const BETWEEN_REFRESHES = 0.18;
    private const BETWEEN_QUEUE_DISPLAYS = 0.58;
    private const MYSQL = ['driver' => 'pdo_mysql', 'host' => 'db.internal', 'port' => null, 'user' => 'root', 'password' => 's3cr3t', 'dbname' => 'shop'];
    private const POSTGRESQL = ['driver' => 'pdo_pgsql', 'host' => 'pg.internal', 'port' => null, 'user' => 'app', 'password' => 's3cr3t', 'dbname' => 'shop'];
    private const QUEUES = '{"transports":["async"]}' . "\n" . '{"name":"async","count":12}' . "\n";

    private MockClock $clock;

    /** @var array<string, list<FakeCollector>> by cost class */
    private array $collectors = [];

    private ComponentRegistry $registry;

    /** @var list<string> the services the Stack screen asked for */
    private array $built = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('@' . self::START);
        $detectors = [];

        foreach (Component::cases() as $component) {
            $specs = [];

            foreach (CostClass::cases() as $cost) {
                $this->collectors[$cost->name][] = $collector = new FakeCollector($component->value . '.' . strtolower($cost->name), ['ok' => true]);
                $specs[] = new CollectorSpec($collector, Lifetime::Dynamic, $cost);
            }

            $detectors[$component->value] = new StaticDetector($component, Detection::found(new CollectorSet($component, $specs)));
        }

        $this->registry = Detectors::registry($detectors, array_map(static fn(): array => ['enabled' => 'auto'], $detectors));
    }

    public function testFiftyDisplaysInTenSecondsCostOneCallPerCollector(): void
    {
        $reader = $this->reader(new CountingCachePool());

        $last = $this->display(static fn(): LocalStackReader => $reader);

        self::assertSame(['Immediate' => [1, 1, 1, 1, 1, 1, 1, 1, 1], 'Network' => [1, 1, 1, 1, 1, 1, 1, 1, 1], 'Slow' => [1, 1, 1, 1, 1, 1, 1, 1, 1]], $this->calls());
        self::assertSame(CacheState::Working, $last->cache);
        self::assertFalse($last->isReading());

        foreach ($last->components as $component) {
            foreach ($component->instances[0]->snapshot->results ?? [] as $result) {
                self::assertSame(ResultState::Fresh, $result->state, $result->name);
            }
        }
    }

    /**
     * @return iterable<string, array{\Closure(): CacheItemPoolInterface, CacheState}>
     */
    public static function cachesThatKeepNothing(): iterable
    {
        $null = new NullAdapter();
        $array = new ArrayAdapter();
        $forgetful = new ForgetfulCachePool();

        yield 'null adapter' => [static fn(): CacheItemPoolInterface => $null, CacheState::OutOfOrder];
        yield 'array adapter' => [static fn(): CacheItemPoolInterface => $array, CacheState::OutOfOrder];
        yield 'accepts writes and keeps nothing' => [static fn(): CacheItemPoolInterface => $forgetful, CacheState::OutOfOrder];
        yield 'a new pool at each display' => [static fn(): CacheItemPoolInterface => new CountingCachePool(), CacheState::Cold];
    }

    /**
     * @param \Closure(): CacheItemPoolInterface $pool
     */
    #[DataProvider('cachesThatKeepNothing')]
    public function testFiftyDisplaysOnACacheThatKeepsNothingCallNoServer(\Closure $pool, CacheState $cache): void
    {
        $last = $this->display(fn(): LocalStackReader => $this->reader($pool()));

        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0], $this->calls()['Network']);
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0], $this->calls()['Slow']);
        self::assertSame([50, 50, 50, 50, 50, 50, 50, 50, 50], $this->calls()['Immediate']);
        self::assertSame($cache, $last->cache);

        foreach (Component::cases() as $component) {
            self::assertSame(ResultState::Fresh, $last->component($component)?->instances[0]->snapshot?->result($component->value . '.immediate')?->state);
        }
    }

    public function testFiftyRefreshesInTenSecondsForgetOnce(): void
    {
        $reader = $this->reader(new CountingCachePool());
        $reader->overview(ReadMode::Page);
        $this->clock->sleep(self::BETWEEN_DISPLAYS);
        $reader->overview(ReadMode::Fragment);
        $this->clock->sleep(1 - self::BETWEEN_DISPLAYS);
        $once = ['Immediate' => [1, 1, 1, 1, 1, 1, 1, 1, 1], 'Network' => [1, 1, 1, 1, 1, 1, 1, 1, 1], 'Slow' => [1, 1, 1, 1, 1, 1, 1, 1, 1]];
        $twice = ['Immediate' => [2, 2, 2, 2, 2, 2, 2, 2, 2], 'Network' => [2, 2, 2, 2, 2, 2, 2, 2, 2], 'Slow' => [2, 2, 2, 2, 2, 2, 2, 2, 2]];
        self::assertSame($once, $this->calls());

        $forgotten = (int) $reader->refresh();
        $reader->overview(ReadMode::Fragment);

        self::assertSame($twice, $this->calls(), 'the first refresh forgets every value');

        for ($i = 1; $i < self::DISPLAYS; ++$i) {
            $this->clock->sleep(self::BETWEEN_REFRESHES);
            $forgotten += (int) $reader->refresh();
            $reader->overview(ReadMode::Fragment);
        }

        self::assertLessThan(self::START + Lifetime::Dynamic->seconds(), $this->clock->now()->getTimestamp(), 'no value expires on its own');
        self::assertSame($twice, $this->calls(), 'no other refresh forgets');
        self::assertSame(1, $forgotten);
    }

    public function testAWarmOverviewCostsAtMostTwoRoundTripsPerInstancePlusTwo(): void
    {
        $cache = new CountingCachePool();
        $reader = $this->reader($cache);
        $reader->overview(ReadMode::Page);
        $reader->overview(ReadMode::Fragment);
        $calls = $this->calls();
        $cache->reset();

        $state = $reader->overview(ReadMode::Fragment);

        self::assertSame($calls, $this->calls(), 'warm: nothing is collected');
        self::assertFalse($state->isReading());
        self::assertLessThanOrEqual(2 * count(Component::cases()) + 2, $cache->roundTrips());
    }

    public function testAWarmStackScreenCostsAtMostTwoRoundTripsPerCardPlusTwo(): void
    {
        $cache = new CountingCachePool();
        $this->renderer($cache)->page(Request::create('/'), 'stack', new FakeHostUrls());
        $this->renderer($cache)->fragment(Request::create('/'), 'stack', new FakeHostUrls());
        $calls = $this->calls();
        $cache->reset();

        $html = (string) $this->renderer($cache)->fragment(Request::create('/'), 'stack', new FakeHostUrls())->getContent();

        self::assertSame($calls, $this->calls(), 'warm: nothing is collected');
        $cards = (new Crawler($html))->filter('[data-jm-component]')->count();
        self::assertSame(count(Component::cases()), $cards);
        self::assertLessThanOrEqual(2 * $cards + 2, $cache->roundTrips(), json_encode($cache->calls, \JSON_THROW_ON_ERROR));
    }

    public function testAnApplicationScreenCostsOneRoundTripForTheBadge(): void
    {
        $cache = new CountingCachePool();
        $this->renderer($cache)->page(Request::create('/'), 'stack', new FakeHostUrls());
        $calls = $this->calls();
        $cache->reset();
        $this->built = [];

        $this->renderer($cache)->page(Request::create('/'), 'requests', new FakeHostUrls());

        self::assertSame(['getItem' => 1], $cache->calls);
        self::assertSame([StackSummary::class], $this->built);
        self::assertSame($calls, $this->calls());
    }

    public function testFiftyPagesAskedForTheRestInTenSecondsCostOneCallPerCollector(): void
    {
        $cache = new CountingCachePool();
        $html = '';

        for ($i = 0; $i < self::DISPLAYS; ++$i) {
            $html = $this->renderer($cache)->page(Request::create('/', parameters: ['rest' => '1']), 'stack', new FakeHostUrls())->html;
            $this->clock->sleep(self::BETWEEN_DISPLAYS);
        }

        self::assertSame(['Immediate' => [1, 1, 1, 1, 1, 1, 1, 1, 1], 'Network' => [1, 1, 1, 1, 1, 1, 1, 1, 1], 'Slow' => [1, 1, 1, 1, 1, 1, 1, 1, 1]], $this->calls());
        self::assertStringNotContainsString('data-jm-rest', $html);
    }

    public function testFiftyMessagesFragmentsInThirtySecondsStartOneProcess(): void
    {
        $cache = new CountingCachePool();
        $runner = (new FakeProcessRunner())->answer(null, self::QUEUES);
        $html = '';

        for ($i = 0; $i < self::DISPLAYS; ++$i) {
            $html = (string) $this->renderer($cache, null, $runner)->fragment(Request::create('/'), 'messages', new FakeHostUrls())->getContent();
            $this->clock->sleep(self::BETWEEN_QUEUE_DISPLAYS);
        }

        self::assertLessThan(self::START + Lifetime::HalfMinute->seconds(), $this->clock->now()->getTimestamp() - self::BETWEEN_QUEUE_DISPLAYS);
        self::assertCount(1, $runner->calls);
        self::assertSame('12', (new Crawler($html))->filter('.jm-facts .jm-fact__value')->text());
    }

    public function testFiftyMessagesPagesStartNone(): void
    {
        $cache = new CountingCachePool();
        $runner = (new FakeProcessRunner())->answer(null, self::QUEUES);
        $html = '';

        for ($i = 0; $i < self::DISPLAYS; ++$i) {
            $html = $this->renderer($cache, null, $runner)->page(Request::create('/'), 'messages', new FakeHostUrls())->html;
            $this->clock->sleep(self::BETWEEN_DISPLAYS);
        }

        self::assertSame([], $runner->calls);
        self::assertStringContainsString('data-jm-rest', $html);
    }

    /**
     * @param \Closure(): CacheItemPoolInterface $pool
     */
    #[DataProvider('cachesThatKeepNothing')]
    public function testACacheThatKeepsNothingNeverStartsTheProcess(\Closure $pool, CacheState $cache): void
    {
        $runner = (new FakeProcessRunner())->answer(null, self::QUEUES);

        for ($i = 0; $i < self::DISPLAYS; ++$i) {
            $this->renderer($pool(), null, $runner)->fragment(Request::create('/'), 'messages', new FakeHostUrls());
            $this->clock->sleep(self::BETWEEN_DISPLAYS);
        }

        self::assertSame([], $runner->calls, $cache->name);
    }

    public function testTheQueuesCostTwoCacheRoundTripsPerDisplay(): void
    {
        $cache = new CountingCachePool();
        $runner = (new FakeProcessRunner())->answer(null, self::QUEUES);
        $this->renderer($cache, null, $runner)->page(Request::create('/'), 'messages', new FakeHostUrls());
        $this->renderer($cache, null, $runner)->fragment(Request::create('/'), 'messages', new FakeHostUrls());
        $cache->reset();

        $html = $this->renderer($cache, null, $runner)->page(Request::create('/'), 'messages', new FakeHostUrls())->html;

        self::assertCount(1, $runner->calls);
        self::assertSame('12', (new Crawler($html))->filter('.jm-facts .jm-fact__value')->text());
        self::assertSame(1 + 2, $cache->roundTrips(), 'the badge, then the queues: ' . json_encode($cache->calls, \JSON_THROW_ON_ERROR));
    }

    public function testAnotherApplicationScreenStillCostsOneRoundTrip(): void
    {
        $cache = new CountingCachePool();
        $runner = (new FakeProcessRunner())->answer(null, self::QUEUES);
        $this->renderer($cache, null, $runner)->page(Request::create('/'), 'messages', new FakeHostUrls());
        $this->renderer($cache, null, $runner)->fragment(Request::create('/'), 'messages', new FakeHostUrls());
        $cache->reset();
        $this->built = [];

        $this->renderer($cache, null, $runner)->page(Request::create('/'), 'requests', new FakeHostUrls());

        self::assertSame(['getItem' => 1], $cache->calls);
        self::assertSame([StackSummary::class], $this->built);
        self::assertCount(1, $runner->calls);
    }

    public function testAStatusPageThatIsNotOneIsProbedOncePerHour(): void
    {
        $pages = new FakeStatusPages();
        $pages->answerAsTheApplication();
        $reader = $this->statusPageReader($pages);
        $reader->overview(ReadMode::Page);

        $this->readFor($reader, 59 * 60);
        self::assertSame(1, $pages->calls);

        $this->clock->sleep(self::START + 61 * 60 - $this->clock->now()->getTimestamp());
        $reader->overview(ReadMode::Fragment);
        self::assertSame(2, $pages->calls);

        self::assertTrue($reader->refresh());
        $reader->overview(ReadMode::Fragment);
        self::assertSame(3, $pages->calls);
    }

    public function testAnUnreachableStatusPageIsProbedOncePerMinute(): void
    {
        $pages = new FakeStatusPages();
        $pages->stopAnswering();
        $reader = $this->statusPageReader($pages);
        $reader->overview(ReadMode::Page);

        $this->readFor($reader, 59);
        self::assertSame(1, $pages->calls);

        $this->clock->sleep(self::START + 61 - $this->clock->now()->getTimestamp());
        $reader->overview(ReadMode::Fragment);
        self::assertSame(2, $pages->calls);
    }

    public function testAnInstanceIsReadInOneRoundTrip(): void
    {
        $ids = ['a', 'a.b', 'é/1'];
        $instances = array_map(static fn(string $id): Instance => Instance::found(Component::Php, new CollectorSet(Component::Php, [
            new CollectorSpec(new FakeCollector('php.status', ['ok' => true]), Lifetime::Dynamic, CostClass::Immediate),
            new CollectorSpec(new FakeCollector('php.remote', ['ok' => true]), Lifetime::Dynamic, CostClass::Network),
            new CollectorSpec(new FakeCollector('php.slow', ['ok' => true]), Lifetime::Dynamic, CostClass::Slow, shared: true),
        ]), $id), $ids);
        $this->registry = Detectors::registry(
            [Component::Php->value => new StaticDetector(Component::Php, Detection::instances($instances))],
            [Component::Php->value => ['enabled' => 'auto']],
        );
        $cache = new CountingCachePool();
        $reader = $this->reader($cache);

        foreach ($ids as $id) {
            $reader->component(Component::Php, $id, ReadMode::Page);
            $reader->component(Component::Php, $id, ReadMode::Fragment);
            $cache->reset();

            $state = $reader->component(Component::Php, $id, ReadMode::Fragment);

            self::assertFalse($state->isReading(), $id);
            self::assertSame(['getItem' => 1, 'getItems' => 1], $cache->calls, 'the probe, then the instance: ' . $id);
        }
    }

    public function testForgettingBuildsNoCollector(): void
    {
        $cache = new CountingCachePool();
        $broken = new FakeCollector('php.remote', collectFailure: new \RuntimeException('Connection refused'));
        $instance = Instance::found(Component::Php, new CollectorSet(Component::Php, [
            new CollectorSpec($broken, Lifetime::Dynamic, CostClass::Network),
        ]), 'main');
        $keys = ['jmonitor.stack.result.%node.web-1.php.main.php.remote', 'jmonitor.stack.failure.%node.web-1.php.main.php.remote'];

        foreach ($keys as $key) {
            $cache->save($cache->getItem($key)->set(['kept' => true]));
        }

        (new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10), $cache, $this->clock, new WebNode('web-1')))->forget([$instance]);

        self::assertSame(0, $broken->collects);

        foreach ($keys as $key) {
            self::assertFalse($cache->hasItem($key), $key);
        }
    }

    public function testAPoolThatThrowsNeverBreaksAnApplicationScreen(): void
    {
        $logger = self::logger();

        $html = $this->renderer(new ThrowingCachePool(), $logger)->page(Request::create('/'), 'requests', new FakeHostUrls())->html;

        $crawler = new Crawler($html);
        self::assertSame('Requests', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        self::assertSame('<span data-jm-slot="nav-stack"></span>', $crawler->filter('[data-jm-slot="nav-stack"]')->outerHtml());
        self::assertSame(['warning The summary of the live stack could not be read: Connection refused'], $logger->messages);
    }

    public function testFiftyDisplaysOfADeadDatabaseAskItOnce(): void
    {
        $container = new class extends Container {
            public int $asked = 0;

            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                ++$this->asked;

                throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
            }
        };
        $cache = new CountingCachePool();
        $reader = fn(): LocalStackReader => $this->mysqlReader($container, ['default' => self::MYSQL], $cache);

        $state = $reader()->overview(ReadMode::Page);
        for ($i = 1; $i < self::DISPLAYS; ++$i) {
            $this->clock->sleep(59 / self::DISPLAYS);
            $state = $reader()->overview(ReadMode::Fragment);
        }

        self::assertLessThan(self::START + 60, $this->clock->now()->getTimestamp());
        self::assertSame(1, $container->asked);
        self::assertSame(ResultState::Failed, $state->component(Component::Mysql)?->instances[0]->snapshot?->result('mysql')?->state);

        $this->clock->sleep(self::START + 61 - $this->clock->now()->getTimestamp());
        $reader()->overview(ReadMode::Fragment);

        self::assertSame(2, $container->asked);
    }

    public function testAFrozenDatabaseMakesOneDisplayWait(): void
    {
        $container = new class ($this->clock) extends Container {
            public int $asked = 0;

            public function __construct(
                private readonly MockClock $clock,
            ) {
                parent::__construct();
            }

            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                ++$this->asked;
                $this->clock->sleep(10);

                throw new \RuntimeException('SQLSTATE[HY000] [2006] MySQL server has gone away');
            }
        };
        $cache = new CountingCachePool();
        $reader = fn(): LocalStackReader => $this->mysqlReader($container, ['default' => self::MYSQL], $cache);
        $detail = null;

        for ($i = 0; $i < self::DISPLAYS; ++$i) {
            $reader()->overview(ReadMode::Page);
            $this->clock->sleep(self::BETWEEN_DISPLAYS);
            $detail = $reader()->component(Component::Mysql, 'default', ReadMode::Fragment);
            $this->clock->sleep(self::BETWEEN_DISPLAYS);
        }

        self::assertSame(1, $container->asked);
        self::assertEqualsWithDelta(self::START + 10 + 2 * self::DISPLAYS * self::BETWEEN_DISPLAYS, (float) $this->clock->now()->format('U.u'), 0.001, 'one wait of ten seconds');
        self::assertFalse($detail?->isReading());

        $snapshot = $detail?->component(Component::Mysql)?->instances[0]->snapshot;

        foreach (['mysql', 'mysql.variables', 'mysql.sizes', 'mysql.slow_queries'] as $name) {
            self::assertSame(ResultState::Failed, $snapshot?->result($name)?->state, $name);
        }
    }

    public function testEightConnectionsOfASilentServerMakeOneDisplayWait(): void
    {
        $container = new class ($this->clock) extends Container {
            public int $asked = 0;

            public function __construct(
                private readonly MockClock $clock,
            ) {
                parent::__construct();
            }

            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                ++$this->asked;
                $this->clock->sleep(30);

                throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection timed out');
            }
        };
        $params = [];
        foreach (range(1, 8) as $i) {
            $params['c' . $i] = ['dbname' => 'shop' . $i] + self::MYSQL;
        }
        $cache = new CountingCachePool();
        $reader = fn(): LocalStackReader => $this->mysqlReader($container, $params, $cache);

        $state = $reader()->overview(ReadMode::Page);
        for ($i = 1; $i < self::DISPLAYS; ++$i) {
            $this->clock->sleep(2);
            $state = $reader()->overview(ReadMode::Fragment);
        }

        self::assertSame(1, $container->asked);
        self::assertSame(self::START + 30 + 2 * (self::DISPLAYS - 1), $this->clock->now()->getTimestamp(), 'one wait of thirty seconds');
        self::assertLessThan(self::START + 30 + 150, $this->clock->now()->getTimestamp(), 'within the time the failure is kept');
        self::assertFalse($state->isReading());
    }

    public function testFiftyDisplaysOfADeadPostgresqlAskItOnce(): void
    {
        $container = new class extends Container {
            public int $asked = 0;

            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                ++$this->asked;

                throw new \RuntimeException('SQLSTATE[08006] [7] connection to server at "pg.internal" (10.0.0.9), port 5432 failed: Connection refused');
            }
        };
        $cache = new CountingCachePool();
        $reader = fn(): LocalStackReader => $this->postgresqlReader($container, ['default' => self::POSTGRESQL], $cache);

        $state = $reader()->overview(ReadMode::Page);
        for ($i = 1; $i < self::DISPLAYS; ++$i) {
            $this->clock->sleep(59 / self::DISPLAYS);
            $state = $reader()->overview(ReadMode::Fragment);
        }

        self::assertLessThan(self::START + 60, $this->clock->now()->getTimestamp());
        self::assertSame(1, $container->asked);
        self::assertSame(ResultState::Failed, $state->component(Component::Postgresql)?->instances[0]->snapshot?->result('postgresql')?->state);

        $this->clock->sleep(self::START + 61 - $this->clock->now()->getTimestamp());
        $reader()->overview(ReadMode::Fragment);

        self::assertSame(2, $container->asked);
    }

    public function testFiftyDisplaysOfADeadRedisAskItOnce(): void
    {
        $asked = 0;
        $dsns = static function () use (&$asked): RedisDsns {
            return new RedisDsns(
                new ServiceLocator(['main' => static fn(): \ArrayObject => new \ArrayObject(['redis://cache.internal:6379'])]),
                ['main'],
                [],
                static function () use (&$asked): FakeRedis {
                    ++$asked;

                    return new FakeRedis(new \RuntimeException('Redis connection failed: Connection refused'));
                },
            );
        };
        $cache = new CountingCachePool();
        $reader = function () use ($dsns, $cache): LocalStackReader {
            $this->registry = Detectors::registry(
                [Component::Redis->value => new RedisDetector($dsns(), false)],
                [Component::Redis->value => ['enabled' => 'auto']],
            );

            return $this->reader($cache);
        };

        $state = $reader()->overview(ReadMode::Page);
        for ($i = 1; $i < self::DISPLAYS; ++$i) {
            $this->clock->sleep(59 / self::DISPLAYS);
            $state = $reader()->overview(ReadMode::Fragment);
        }

        self::assertLessThan(self::START + 60, $this->clock->now()->getTimestamp());
        self::assertSame(1, $asked);
        self::assertSame(ResultState::Failed, $state->component(Component::Redis)?->instances[0]->snapshot?->result('redis')?->state);

        $this->clock->sleep(self::START + 61 - $this->clock->now()->getTimestamp());
        $reader()->overview(ReadMode::Fragment);

        self::assertSame(2, $asked);
    }

    public function testEightPostgresqlConnectionsOfASilentServerMakeOneDisplayWait(): void
    {
        $container = new class ($this->clock) extends Container {
            public int $asked = 0;

            public function __construct(
                private readonly MockClock $clock,
            ) {
                parent::__construct();
            }

            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                ++$this->asked;
                $this->clock->sleep(30);

                throw new \RuntimeException('SQLSTATE[08006] [7] connection to server at "pg.internal" (10.0.0.9), port 5432 failed: timeout expired');
            }
        };
        $params = [];
        foreach (range(1, 8) as $i) {
            $params['c' . $i] = ['dbname' => 'shop' . $i] + self::POSTGRESQL;
        }
        $cache = new CountingCachePool();
        $reader = fn(): LocalStackReader => $this->postgresqlReader($container, $params, $cache);

        $state = $reader()->overview(ReadMode::Page);
        for ($i = 1; $i < self::DISPLAYS; ++$i) {
            $this->clock->sleep(2);
            $state = $reader()->overview(ReadMode::Fragment);
        }

        self::assertCount(8, $state->component(Component::Postgresql)->instances ?? []);
        self::assertSame(1, $container->asked);
        self::assertSame(self::START + 30 + 2 * (self::DISPLAYS - 1), $this->clock->now()->getTimestamp(), 'one wait of thirty seconds');
        self::assertLessThan(self::START + 30 + 150, $this->clock->now()->getTimestamp(), 'within the time the failure is kept');
        self::assertFalse($state->isReading());

        foreach ($state->component(Component::Postgresql)->instances ?? [] as $instance) {
            self::assertSame(ResultState::Failed, $instance->snapshot?->result('postgresql')?->state, (string) $instance->id);
        }
    }

    public function testADisplayKilledWhileItReadsCostsOneWait(): void
    {
        $stops = [];
        $request = new PhpRequest(null, static fn(): int => 0, static function (\Closure $stop) use (&$stops): void {
            $stops[] = $stop;
        }, static fn(): array => ['type' => \E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded', 'file' => '/app/vendor/doctrine/dbal/src/Driver/PDO/PDOConnect.php', 'line' => 28]);
        $cache = new FreezableCachePool(new CountingCachePool());
        $killed = static function () use (&$stops, $cache): void {
            foreach ($stops as $stop) {
                $stop();
            }
            $cache->frozen = true;
        };
        $container = new class ($this->clock, $killed) extends Container {
            public int $asked = 0;

            public function __construct(
                private readonly MockClock $clock,
                private readonly \Closure $killed,
            ) {
                parent::__construct();
            }

            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                ++$this->asked;
                $this->clock->sleep(30);
                ($this->killed)();

                throw new \RuntimeException('SQLSTATE[08006] [7] connection to server at "pg.internal" (10.0.0.9), port 5432 failed: timeout expired');
            }
        };
        $params = [];
        foreach (range(1, 8) as $i) {
            $params['c' . $i] = ['dbname' => 'shop' . $i] + self::POSTGRESQL;
        }
        $reader = fn(): LocalStackReader => $this->postgresqlReader($container, $params, $cache, $request);

        $reader()->overview(ReadMode::Page);
        $reader()->overview(ReadMode::Fragment);
        $cache->frozen = false;

        self::assertSame(1, $container->asked);

        for ($i = 0; $i < self::DISPLAYS; ++$i) {
            $this->clock->sleep(2);
            $state = $reader()->overview(ReadMode::Fragment);

            self::assertFalse($state->isReading(), 'display ' . $i);

            foreach ($state->component(Component::Postgresql)->instances ?? [] as $instance) {
                self::assertSame(ResultState::Failed, $instance->snapshot?->result('postgresql')?->state, 'display ' . $i . ', ' . $instance->id);
                self::assertSame('The server did not answer before PHP stopped the request reading it.', $instance->snapshot->result('postgresql')->error);
            }
        }

        self::assertSame(1, $container->asked);
        self::assertCount(8, $state->component(Component::Postgresql)->instances ?? []);
        self::assertLessThan(self::START + 30 + 150, $this->clock->now()->getTimestamp(), 'within the time the failure is kept');
    }

    public function testARefusedConnectionStaysCriticalWhileAnotherReadsItsServer(): void
    {
        $container = new class extends Container {
            public int $refused = 0;

            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                if ($id === 'doctrine.dbal.a_connection') {
                    ++$this->refused;

                    throw new \RuntimeException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'10.0.0.1' (using password: YES)");
                }

                return parent::get($id, $invalidBehavior);
            }
        };
        $container->set('doctrine.dbal.b_connection', new FakeDatabase(['@@performance_schema' => [['enabled' => '1']]]));
        $cache = new CountingCachePool();
        $reader = fn(): LocalStackReader => $this->mysqlReader($container, ['a' => self::MYSQL, 'b' => ['dbname' => 'reports'] + self::MYSQL], $cache);
        $checks = new StackChecks([], false, $this->clock);
        $freshB = 0;
        $reader()->overview(ReadMode::Page);
        $startedAt = $this->clock->now()->getTimestamp();

        for ($i = 0; $i < 200; ++$i) {
            $id = $i % 2 === 0 ? 'a' : 'b';
            $state = $reader()->component(Component::Mysql, $id, ReadMode::Fragment);
            $result = $state->component(Component::Mysql)?->instances[$id === 'a' ? 0 : 1]->snapshot?->result('mysql');

            if ($id === 'a') {
                self::assertNotSame(ResultState::Fresh, $result?->state, 'read ' . $i);
                self::assertSame(Verdict::Critical, $checks->health($state)->verdict(Component::Mysql, 'a'), 'read ' . $i);
            } elseif ($result?->state === ResultState::Fresh) {
                ++$freshB;
            }

            $this->clock->sleep(3);
        }

        self::assertSame($startedAt + 600, $this->clock->now()->getTimestamp());
        self::assertSame(100, $freshB, 'the other connection reads the server meanwhile');
        self::assertSame(10, $container->refused, 'once at the end of each sixty seconds the failure is kept');
    }

    public function testTwoConnectionsOnOneServerReadItsStatusOnce(): void
    {
        $container = new Container();
        $databases = [];
        foreach (['default', 'reporting'] as $name) {
            $container->set('doctrine.dbal.' . $name . '_connection', $databases[$name] = new FakeDatabase(['@@performance_schema' => [['enabled' => '1']]]));
        }
        $cache = new CountingCachePool();

        $this->mysqlReader($container, ['default' => self::MYSQL, 'reporting' => ['dbname' => 'reports'] + self::MYSQL], $cache)->overview(ReadMode::Page);
        $state = $this->mysqlReader($container, ['default' => self::MYSQL, 'reporting' => ['dbname' => 'reports'] + self::MYSQL], $cache)->overview(ReadMode::Fragment);

        self::assertFalse($state->isReading());
        self::assertCount(2, $state->component(Component::Mysql)->instances ?? []);
        $statusQueries = array_filter(
            [...$databases['default']->queries, ...$databases['reporting']->queries],
            static fn(string $query): bool => str_contains($query, 'SHOW GLOBAL STATUS WHERE'),
        );
        self::assertCount(1, $statusQueries);
    }

    /**
     * @param \Closure(): LocalStackReader $reader the reader of each display
     */
    private function display(\Closure $reader): StackState
    {
        $state = $reader()->overview(ReadMode::Page);

        for ($i = 1; $i < self::DISPLAYS; ++$i) {
            $this->clock->sleep(self::BETWEEN_DISPLAYS);
            $state = $reader()->overview(ReadMode::Fragment);
        }

        return $state;
    }

    private function readFor(LocalStackReader $reader, int $seconds): void
    {
        for ($i = 0; $i < self::DISPLAYS; ++$i) {
            $reader->overview(ReadMode::Fragment);
            $this->clock->sleep($seconds / self::DISPLAYS);
        }
    }

    private function statusPageReader(FakeStatusPages $pages): LocalStackReader
    {
        $collectors = new CollectorSet(Component::Nginx, [
            new CollectorSpec(new NginxStatusCollector('http://localhost/nginx_status', $pages), Lifetime::Dynamic, CostClass::Network, trackDelta: true),
        ]);
        $this->registry = Detectors::registry(
            [Component::Nginx->value => new StaticDetector(Component::Nginx, Detection::found($collectors))],
            [Component::Nginx->value => ['enabled' => 'auto']],
        );

        return $this->reader(new CountingCachePool());
    }

    /**
     * A reader of the MySQL servers of these connections only, detected again as each request does.
     *
     * @param array<string, array<string, mixed>> $params the parameters of each connection
     */
    private function mysqlReader(Container $container, array $params, CacheItemPoolInterface $cache): LocalStackReader
    {
        $names = array_map(strval(...), array_keys($params));
        $instances = new DatabaseInstances(
            new DoctrineConnections(new ServiceLocator(array_map(static fn(array $connection): \Closure => static fn(): \ArrayObject => new \ArrayObject($connection), $params)), $names),
            $container,
            array_combine($names, array_map(static fn(string $name): string => 'doctrine.dbal.' . $name . '_connection', $names)),
            [],
        );
        $this->registry = Detectors::registry(
            [Component::Mysql->value => new MysqlDetector($instances, [], false)],
            [Component::Mysql->value => ['enabled' => 'auto']],
        );

        return $this->reader($cache);
    }

    /**
     * A reader of the PostgreSQL databases of these connections only, detected again as each request does.
     *
     * @param array<string, array<string, mixed>> $params the parameters of each connection
     */
    private function postgresqlReader(Container $container, array $params, CacheItemPoolInterface $cache, ?PhpRequest $request = null): LocalStackReader
    {
        $names = array_map(strval(...), array_keys($params));
        $instances = new DatabaseInstances(
            new DoctrineConnections(new ServiceLocator(array_map(static fn(array $connection): \Closure => static fn(): \ArrayObject => new \ArrayObject($connection), $params)), $names),
            $container,
            array_combine($names, array_map(static fn(string $name): string => 'doctrine.dbal.' . $name . '_connection', $names)),
            [],
        );
        $this->registry = Detectors::registry(
            [Component::Postgresql->value => new PostgresqlDetector($instances, [], 'public', false)],
            [Component::Postgresql->value => ['enabled' => 'auto']],
        );

        return $this->reader($cache, request: $request);
    }

    /**
     * @return array<string, list<int>> the calls of each collector, by cost class
     */
    private function calls(): array
    {
        return array_map(static fn(array $collectors): array => array_map(static fn(FakeCollector $collector): int => $collector->collects, $collectors), $this->collectors);
    }

    private function reader(CacheItemPoolInterface $cache, ?FakeProcessRunner $runner = null, ?PhpRequest $request = null): LocalStackReader
    {
        $node = new WebNode('web-1');
        $snapshots = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10), $cache, $this->clock, $node, request: $request);
        $queues = $runner === null ? null : new QueuesInstance(new QueuesCollector($runner, new PhpCli(static fn(): string => '/usr/local/bin/php'), \dirname(__DIR__) . '/Fixtures/App', 'prod', false));

        return new LocalStackReader($this->registry, $snapshots, new CacheProbe($cache, $this->clock), $cache, $this->clock, $node, null, $queues);
    }

    /**
     * The page of one request: the Requests and Stack screens on a reader, checks and summary of their own,
     * which share the collectors and the cache of the test, and the Messages screen when the queues are counted.
     */
    private function renderer(CacheItemPoolInterface $cache, ?LoggerInterface $logger = null, ?FakeProcessRunner $queues = null): PageRenderer
    {
        $node = new WebNode('web-1');
        $services = [
            StackReaderInterface::class => fn(): StackReaderInterface => $this->reader($cache, $queues),
            StackChecks::class => fn(): StackChecks => new StackChecks([], false, $this->clock),
            StackSummary::class => fn(): StackSummary => new StackSummary(new ServiceLocator(['cache' => static fn(): CacheItemPoolInterface => $cache]), $node, $this->clock, $logger),
        ];
        $locator = new ServiceLocator(array_map(fn(\Closure $factory): \Closure => function () use ($factory): object {
            $service = $factory();
            $this->built[] = $service instanceof StackReaderInterface ? StackReaderInterface::class : $service::class;

            return $service;
        }, $services));
        $screen = new StackScreen($locator, new CardBuilder($this->clock), $this->clock, new ServiceLocator([]));
        $screens = [new RequestsScreen(), ...($queues === null ? [] : [new MessagesScreen(true, true, new QueuesBlocks($locator, $this->clock))]), $screen];

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'Jmonitor');
        $twig = new Environment($loader, ['autoescape' => false, 'strict_variables' => true]);
        $twig->addExtension(new JmonitorExtension());

        return new PageRenderer(
            new ScreenRegistry($screens),
            new ScreenBuilder(new FixedReader(), true, $this->clock),
            null,
            $twig,
            new Assets(),
            new ActionGuard(),
            $logger,
        );
    }

    /** @return AbstractLogger&object{messages: list<string>} */
    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = $level . ' ' . strtr((string) $message, ['{reason}' => (string) ($context['reason'] ?? '')]);
            }
        };
    }
}
