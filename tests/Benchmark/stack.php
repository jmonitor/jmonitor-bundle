<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\Detectors;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const OVERVIEW_RUNS = 200;
const RENEWAL_RUNS = 50;
const COLD_RUNS = 5;
const DISPLAYS = 50;
const CYCLES = '[{"cycle": "8.4", "eol": "2028-12-31", "support": "2026-12-31"}]';

function report(string $measure, float $value, string $unit, ?float $target, string $note = '', bool $atMost = false): void
{
    printf(
        "%-62s %10.2f %-5s %s%s\n",
        $measure . ':',
        $value,
        $unit,
        $target === null ? '(no target)' : sprintf('(target %s %s %s: %s)', $atMost ? '<=' : '<', $target, $unit, ($atMost ? $value <= $target : $value < $target) ? 'met' : 'MISSED'),
        $note === '' ? '' : ' ' . $note,
    );
}

function workDir(): string
{
    $dir = sys_get_temp_dir() . '/jmonitor-bench-stack-' . bin2hex(random_bytes(6));
    mkdir($dir);

    return $dir;
}

function filesystemCache(string $dir): CountingCachePool
{
    return new CountingCachePool(new FilesystemAdapter('jmonitor-stack', 0, $dir));
}

/**
 * The services of config/stack.php, with the real detectors of Server, PHP and Symfony.
 *
 * @return array{LocalStackReader, ComponentRegistry}
 */
function engine(CacheItemPoolInterface $cache, ClockInterface $clock, KernelInterface $kernel, ?FakeUrlFetcher $endOfLife = null): array
{
    $detectors = [
        'system' => new SystemDetector(),
        'php' => $endOfLife === null ? new PhpDetector(false) : new PhpDetector(true, $endOfLife),
        'symfony' => $endOfLife === null ? new SymfonyDetector($kernel, new ProcessRunner(), false, false) : new SymfonyDetector($kernel, new ProcessRunner(), false, true, $endOfLife),
    ];
    $registry = Detectors::registry($detectors, array_map(static fn(): array => ['enabled' => 'auto'], $detectors));
    $node = new WebNode();
    $snapshots = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10, 300), $cache, $clock, $node);

    return [new LocalStackReader($registry, $snapshots, new CacheProbe($cache, $clock), $cache, $clock, $node), $registry];
}

function instances(StackState $state): int
{
    return array_sum(array_map(static fn(ComponentState $component): int => count($component->instances), $state->components));
}

function millis(callable $operation): float
{
    $start = hrtime(true);
    $operation();

    return (hrtime(true) - $start) / 1e6;
}

$kernel = new TestKernel('prod', false, ['stack' => ['end_of_life' => false, 'symfony' => ['flex' => false]]], withDoctrine: false, withHttpClient: false);
$kernel->boot();
$dirs = [$kernel->getVarDir()];
$clock = new NativeClock();

$dirs[] = $dir = workDir();
$cache = filesystemCache($dir);
[$reader, $registry] = engine($cache, $clock, $kernel);
$reader->overview(ReadMode::Page);
$reader->overview(ReadMode::Fragment);
$registry->reset();
$cache->reset();
$detectMillis = millis(static fn(): array => array_map($registry->status(...), Component::cases()));
report('detection of the three components, warm, cache round trips', $cache->roundTrips(), 'trips', 0, sprintf('(%.2f ms)', $detectMillis), true);

$registry->reset();
$cache->reset();
$state = $reader->overview(ReadMode::Fragment);
$instances = instances($state);
report('overview, everything in cache, cache round trips', $cache->roundTrips(), 'trips', 2 * $instances + 2, sprintf('(%d instances)', $instances), true);

$total = 0.0;
for ($i = 0; $i < OVERVIEW_RUNS; ++$i) {
    $registry->reset();
    $total += millis(static fn(): StackState => $reader->overview(ReadMode::Fragment));
}
report('overview, everything in cache, duration', $total / OVERVIEW_RUNS, 'ms', 15, sprintf('(mean of %d, detection included)', OVERVIEW_RUNS));

$dirs[] = $dir = workDir();
$mockClock = new MockClock();
[$reader, $registry] = engine(filesystemCache($dir), $mockClock, $kernel);
$reader->overview(ReadMode::Page);
$reader->overview(ReadMode::Fragment);
$total = 0.0;
$renewed = 0;
for ($i = 0; $i < RENEWAL_RUNS; ++$i) {
    $mockClock->sleep(11);
    $registry->reset();
    $total += millis(static function () use ($reader, $mockClock, &$renewed): void {
        foreach ($reader->overview(ReadMode::Fragment)->components as $component) {
            foreach ($component->instances[0]->snapshot->results ?? [] as $result) {
                $renewed += (int) ($result->collectedAt === $mockClock->now()->getTimestamp());
            }
        }
    });
}
report('dynamic renewal of Server, PHP and Symfony, duration', $total / RENEWAL_RUNS, 'ms', null, sprintf('(mean of %d, %d values renewed; the 150 ms target is for a stack with Nginx, MySQL and Redis)', RENEWAL_RUNS, $renewed));

$slowest = 0.0;
$mostCalls = 0;
for ($i = 0; $i < COLD_RUNS; ++$i) {
    $dirs[] = $dir = workDir();
    [$reader] = engine(filesystemCache($dir), $clock, $kernel);
    $state = null;
    $slowest = max($slowest, millis(static function () use ($reader, &$state): void {
        $state = $reader->overview(ReadMode::Page);
    }));
    $calls = 1;
    while ($state->isReading() && $calls < 10) {
        $state = $reader->overview(ReadMode::Fragment);
        ++$calls;
    }
    $mostCalls = max($mostCalls, $state->isReading() ? PHP_INT_MAX : $calls);
}
report('first display on an empty cache, page duration', $slowest, 'ms', 1200, sprintf('(slowest of %d)', COLD_RUNS));
report('first display on an empty cache, calls before a complete state', $mostCalls, 'calls', 3, sprintf('(most of %d)', COLD_RUNS), true);

$outOfOrder = new FakeUrlFetcher(CYCLES);
[$reader] = engine(new ArrayAdapter(), $clock, $kernel, $outOfOrder);
$cacheState = null;
for ($i = 0; $i < DISPLAYS; ++$i) {
    $cacheState = $reader->overview($i === 0 ? ReadMode::Page : ReadMode::Fragment)->cache;
}
$dirs[] = $dir = workDir();
$working = new FakeUrlFetcher(CYCLES);
[$reader] = engine(filesystemCache($dir), $clock, $kernel, $working);
for ($i = 0; $i < DISPLAYS; ++$i) {
    $reader->overview($i === 0 ? ReadMode::Page : ReadMode::Fragment);
}
report(
    sprintf('cache out of order, %d displays, network or slow collects', DISPLAYS),
    $outOfOrder->calls,
    'calls',
    0,
    sprintf('(cache %s; end of life read offline; %d on a working cache)', $cacheState?->value, $working->calls),
    true,
);

$kernel->shutdown();
(new Filesystem())->remove($dirs);
