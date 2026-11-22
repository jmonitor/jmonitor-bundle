<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\Detectors;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\StaticDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ForgetfulCachePool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * Viewers of the stack within one lifetime of its values cost the monitored servers what one viewer costs.
 */
final class DisplayCostTest extends TestCase
{
    private const START = 1_791_799_200;
    private const DISPLAYS = 50;
    private const BETWEEN_DISPLAYS = 0.2;
    private const BETWEEN_REFRESHES = 0.18;

    private MockClock $clock;

    /** @var array<string, list<FakeCollector>> by cost class */
    private array $collectors = [];

    private ComponentRegistry $registry;

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

        self::assertSame(['Immediate' => [1, 1, 1], 'Network' => [1, 1, 1], 'Slow' => [1, 1, 1]], $this->calls());
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

        self::assertSame([0, 0, 0], $this->calls()['Network']);
        self::assertSame([0, 0, 0], $this->calls()['Slow']);
        self::assertSame([50, 50, 50], $this->calls()['Immediate']);
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
        $once = ['Immediate' => [1, 1, 1], 'Network' => [1, 1, 1], 'Slow' => [1, 1, 1]];
        $twice = ['Immediate' => [2, 2, 2], 'Network' => [2, 2, 2], 'Slow' => [2, 2, 2]];
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

    /**
     * @return array<string, list<int>> the calls of each collector, by cost class
     */
    private function calls(): array
    {
        return array_map(static fn(array $collectors): array => array_map(static fn(FakeCollector $collector): int => $collector->collects, $collectors), $this->collectors);
    }

    private function reader(CacheItemPoolInterface $cache): LocalStackReader
    {
        $node = new WebNode('web-1');
        $snapshots = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10), $cache, $this->clock, $node);

        return new LocalStackReader($this->registry, $snapshots, new CacheProbe($cache, $this->clock), $cache, $this->clock, $node);
    }
}
