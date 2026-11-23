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
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\QueuesInstance;
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
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FakeHostUrls;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeStatusPages;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\Detectors;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\StaticDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ForgetfulCachePool;
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

        self::assertSame(['Immediate' => [1, 1, 1, 1, 1, 1], 'Network' => [1, 1, 1, 1, 1, 1], 'Slow' => [1, 1, 1, 1, 1, 1]], $this->calls());
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

        self::assertSame([0, 0, 0, 0, 0, 0], $this->calls()['Network']);
        self::assertSame([0, 0, 0, 0, 0, 0], $this->calls()['Slow']);
        self::assertSame([50, 50, 50, 50, 50, 50], $this->calls()['Immediate']);
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
        $once = ['Immediate' => [1, 1, 1, 1, 1, 1], 'Network' => [1, 1, 1, 1, 1, 1], 'Slow' => [1, 1, 1, 1, 1, 1]];
        $twice = ['Immediate' => [2, 2, 2, 2, 2, 2], 'Network' => [2, 2, 2, 2, 2, 2], 'Slow' => [2, 2, 2, 2, 2, 2]];
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

        self::assertSame(['Immediate' => [1, 1, 1, 1, 1, 1], 'Network' => [1, 1, 1, 1, 1, 1], 'Slow' => [1, 1, 1, 1, 1, 1]], $this->calls());
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

    public function testAPoolThatThrowsNeverBreaksAnApplicationScreen(): void
    {
        $logger = self::logger();

        $html = $this->renderer(new ThrowingCachePool(), $logger)->page(Request::create('/'), 'requests', new FakeHostUrls())->html;

        $crawler = new Crawler($html);
        self::assertSame('Requests', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        self::assertSame('<span data-jm-slot="nav-stack"></span>', $crawler->filter('[data-jm-slot="nav-stack"]')->outerHtml());
        self::assertSame(['warning The summary of the live stack could not be read: Connection refused'], $logger->messages);
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
     * @return array<string, list<int>> the calls of each collector, by cost class
     */
    private function calls(): array
    {
        return array_map(static fn(array $collectors): array => array_map(static fn(FakeCollector $collector): int => $collector->collects, $collectors), $this->collectors);
    }

    private function reader(CacheItemPoolInterface $cache, ?FakeProcessRunner $runner = null): LocalStackReader
    {
        $node = new WebNode('web-1');
        $snapshots = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10), $cache, $this->clock, $node);
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
