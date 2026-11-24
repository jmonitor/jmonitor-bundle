<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Dashboard\ActionGuard;
use Jmonitor\JmonitorBundle\Dashboard\Assets;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\Screen\MessagesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpCli;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\QueuesCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentDetectorInterface;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\MysqlDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\NginxDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\PostgresqlDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\QueuesInstance;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\QueuesBlocks;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\MysqlDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ServerDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\SymfonyDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\StackScreen;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FakeHostUrls;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeStatusPages;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\Detectors;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const OVERVIEW_RUNS = 200;
const RENEWAL_RUNS = 50;
const COLD_RUNS = 5;
const DISPLAYS = 50;
const SCREEN_RUNS = 200;
const CYCLES = '[{"cycle": "8.4", "eol": "2028-12-31", "support": "2026-12-31"}]';
const QUEUES = '{"transports":["async","failed"]}' . "\n" . '{"name":"async","count":12}' . "\n" . '{"name":"failed","count":0}' . "\n";

function report(string $measure, float $value, string $unit, ?float $target, string $note = '', bool $atMost = false): void
{
    printf(
        "%-76s %10.2f %-5s %s%s\n",
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
 * Nginx serving the page, its status page and its two commands played by fakes, and the command counting the queues.
 */
final class WebStack
{
    public readonly FakeStatusPages $pages;
    public readonly FakeProcessRunner $nginx;
    public readonly FakeProcessRunner $queues;

    public function __construct()
    {
        $fixtures = dirname(__DIR__) . '/Stack/Fixtures/Nginx/';
        $this->pages = new FakeStatusPages();
        $this->nginx = (new FakeProcessRunner())
            ->answer(['sh', '-c', 'nginx -V 2>&1'], (string) file_get_contents($fixtures . 'nginx-V.txt'))
            ->answer(['sh', '-c', 'nginx -T'], (string) file_get_contents($fixtures . 'nginx-T.txt'))
            ->answer(['sh', '-c', 'getconf _NPROCESSORS_ONLN'], "4\n");
        $this->queues = (new FakeProcessRunner())->answer(null, QUEUES);
    }

    public function calls(): int
    {
        return $this->pages->calls + count($this->nginx->calls) + count($this->queues->calls);
    }

    public function reset(): void
    {
        $this->pages->calls = 0;
        $this->nginx->calls = [];
        $this->queues->calls = [];
    }
}

/**
 * @param array<string, ComponentDetectorInterface> $remote the detectors of the remote servers, under the name of their component
 *
 * @return array{LocalStackReader, ComponentRegistry}
 */
function engine(CacheItemPoolInterface $cache, ClockInterface $clock, KernelInterface $kernel, ?FakeUrlFetcher $endOfLife = null, ?WebStack $web = null, ?MysqlDetector $mysql = null, array $remote = []): array
{
    $detectors = [
        'system' => new SystemDetector(),
        'php' => $endOfLife === null ? new PhpDetector(false) : new PhpDetector(true, $endOfLife),
        'symfony' => $endOfLife === null ? new SymfonyDetector($kernel, new ProcessRunner(), false, false) : new SymfonyDetector($kernel, new ProcessRunner(), false, true, $endOfLife),
    ];
    $queues = null;
    if ($web !== null) {
        $requests = new RequestStack();
        $requests->push(Request::create('/', server: ['SERVER_SOFTWARE' => 'nginx/1.27.3']));
        $detectors['nginx'] = new NginxDetector('http://localhost/nginx_status', $requests, $web->nginx, false, $web->pages);
        $queues = new QueuesInstance(new QueuesCollector($web->queues, new PhpCli(static fn(): string => '/usr/local/bin/php'), dirname(__DIR__) . '/Fixtures/App', 'prod', false));
    }
    if ($mysql !== null) {
        $detectors['mysql'] = $mysql;
    }
    $detectors += $remote;
    $registry = Detectors::registry($detectors, array_map(static fn(): array => ['enabled' => 'auto'], $detectors));
    $node = new WebNode();
    $snapshots = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10, 300), $cache, $clock, $node);

    return [new LocalStackReader($registry, $snapshots, new CacheProbe($cache, $clock), $cache, $clock, $node, null, $queues), $registry];
}

/**
 * The Stack screen of one request, on services of its own, as the container gives them.
 *
 * @param list<string>                          $built  the services of the stack the screen asked for
 * @param array<string, ComponentDetectorInterface> $remote
 */
function stackScreen(CacheItemPoolInterface $cache, ClockInterface $clock, KernelInterface $kernel, array &$built, ?FakeUrlFetcher $endOfLife = null, ?WebStack $web = null, ?MysqlDetector $mysql = null, array $remote = []): StackScreen
{
    return screens($cache, $clock, $kernel, $built, $endOfLife, $web, $mysql, $remote)[0];
}

/**
 * The Stack and Messages screens of one request, on services of their own, as the container gives them.
 *
 * @param list<string>                          $built  the services of the stack the screens asked for
 * @param array<string, ComponentDetectorInterface> $remote
 *
 * @return array{StackScreen, MessagesScreen} Messages counts the queues when $web is given
 */
function screens(CacheItemPoolInterface $cache, ClockInterface $clock, KernelInterface $kernel, array &$built, ?FakeUrlFetcher $endOfLife = null, ?WebStack $web = null, ?MysqlDetector $mysql = null, array $remote = []): array
{
    $node = new WebNode();
    $services = [
        StackReaderInterface::class => static fn(): StackReaderInterface => engine($cache, $clock, $kernel, $endOfLife, $web, $mysql, $remote)[0],
        StackChecks::class => static fn(): StackChecks => new StackChecks([], false, $clock),
        StackSummary::class => static fn(): StackSummary => new StackSummary(new ServiceLocator(['cache' => static fn(): CacheItemPoolInterface => $cache]), $node, $clock),
    ];
    $counted = [];

    foreach ($services as $id => $factory) {
        $counted[$id] = static function () use ($id, $factory, &$built): object {
            $built[] = $id;

            return $factory();
        };
    }

    $locator = new ServiceLocator($counted);

    return [
        new StackScreen($locator, new CardBuilder($clock), $clock, new ServiceLocator([
            Component::System->value => static fn(): ServerDetail => new ServerDetail(),
            Component::Php->value => static fn(): PhpDetail => new PhpDetail(),
            Component::Symfony->value => static fn(): SymfonyDetail => new SymfonyDetail(),
            Component::Mysql->value => static fn(): MysqlDetail => new MysqlDetail(),
        ])),
        new MessagesScreen(true, true, $web === null ? null : new QueuesBlocks($locator, $clock)),
    ];
}

/**
 * @param array{StackScreen, MessagesScreen}|StackScreen $screens
 */
function renderer(array|StackScreen $screens, Environment $twig, ClockInterface $clock, FixedReader $storage = new FixedReader()): PageRenderer
{
    return new PageRenderer(
        new ScreenRegistry([new RequestsScreen(), ...(is_array($screens) ? $screens : [$screens])]),
        new ScreenBuilder($storage, true, $clock),
        null,
        $twig,
        new Assets(),
        new ActionGuard(),
        null,
    );
}

/**
 * @return array{float, int} the slowest first page in ms, and the most refreshes it took to read everything
 */
function coldDisplays(ClockInterface $clock, KernelInterface $kernel, Environment $twig, ?string $cycles, array &$dirs): array
{
    $urls = new FakeHostUrls();
    $built = [];
    $slowest = 0.0;
    $mostRefreshes = 0;

    for ($i = 0; $i < COLD_RUNS; ++$i) {
        $dirs[] = $dir = workDir();
        $cache = filesystemCache($dir);
        $endOfLife = $cycles === null ? null : new FakeUrlFetcher($cycles);
        $html = '';
        $slowest = max($slowest, millis(static function () use ($cache, $clock, $kernel, $twig, $urls, $endOfLife, &$built, &$html): void {
            $html = renderer(stackScreen($cache, $clock, $kernel, $built, $endOfLife), $twig, $clock)->page(Request::create('/'), 'stack', $urls)->html;
        }));
        $refreshes = 0;
        while (str_contains($html, 'data-jm-reading') && $refreshes < 10) {
            $html = (string) renderer(stackScreen($cache, $clock, $kernel, $built, $endOfLife), $twig, $clock)->fragment(Request::create('/'), 'stack', $urls)->getContent();
            ++$refreshes;
        }
        $mostRefreshes = max($mostRefreshes, str_contains($html, 'data-jm-reading') ? PHP_INT_MAX : $refreshes);
    }

    return [$slowest, $mostRefreshes];
}

/**
 * The real clock moved forward at will.
 */
final class ShiftedClock implements ClockInterface
{
    public float $shift = 0.0;

    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true) + $this->shift)) ?: throw new LogicException('The clock cannot be read.');
    }
}

/**
 * A MySQL server that accepts connections and never answers: the system completes the handshake of a listening socket.
 */
final class FrozenServer
{
    /** @var resource */
    private $socket;

    public readonly int $port;

    public function __construct()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        if ($socket === false) {
            throw new RuntimeException($error);
        }
        $this->socket = $socket;
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    }

    public function attempts(): int
    {
        $attempts = 0;
        while (($connection = @stream_socket_accept($this->socket, 0)) !== false) {
            ++$attempts;
            fclose($connection);
        }

        return $attempts;
    }
}

/**
 * The connections the server counts so far, this one included.
 */
function serverConnections(string $url): int
{
    $parts = parse_url($url);
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d', $parts['host'] ?? '127.0.0.1', $parts['port'] ?? 3306), urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? ''));
    $row = $pdo->query("SHOW GLOBAL STATUS LIKE 'Connections'")?->fetch(PDO::FETCH_NUM);

    return (int) ($row[1] ?? 0);
}

function mysqlDetector(KernelInterface $kernel): MysqlDetector
{
    $detector = $kernel->getContainer()->get(MysqlDetector::class);
    assert($detector instanceof MysqlDetector);

    return $detector;
}

/**
 * @return array{postgresql: PostgresqlDetector, redis: RedisDetector}
 */
function remoteDetectors(KernelInterface $kernel): array
{
    $postgresql = $kernel->getContainer()->get(PostgresqlDetector::class);
    $redis = $kernel->getContainer()->get(RedisDetector::class);
    assert($postgresql instanceof PostgresqlDetector && $redis instanceof RedisDetector);

    return ['postgresql' => $postgresql, 'redis' => $redis];
}

/**
 * The connections the Redis server received so far, this one included.
 */
function redisConnections(string $url): int
{
    $parts = parse_url($url);
    $redis = new Redis();
    $redis->connect($parts['host'] ?? '127.0.0.1', $parts['port'] ?? 6379, 1.0);
    $received = (int) ($redis->info('stats')['total_connections_received'] ?? 0);
    $redis->close();

    return $received;
}

/**
 * @param array<string, ComponentDetectorInterface> $remote
 *
 * @return array{float, float} the mean duration in ms, and the values read per renewal
 */
function renewals(KernelInterface $kernel, WebStack $web, MysqlDetector $mysql, array $remote, array &$dirs): array
{
    $dirs[] = $dir = workDir();
    $mockClock = new MockClock();
    [$reader, $registry] = engine(filesystemCache($dir), $mockClock, $kernel, null, $web, $mysql, $remote);
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

    return [$total / RENEWAL_RUNS, $renewed / RENEWAL_RUNS];
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

$loader = new FilesystemLoader();
$loader->addPath(dirname(__DIR__, 2) . '/templates', 'Jmonitor');
$twig = new Environment($loader, ['strict_variables' => true]);
$twig->addExtension(new JmonitorExtension());
$urls = new FakeHostUrls();
$built = [];

$dirs[] = $dir = workDir();
$cache = filesystemCache($dir);
renderer(stackScreen($cache, $clock, $kernel, $built), $twig, $clock)->page(Request::create('/'), 'stack', $urls);
$cache->reset();
$built = [];
$applicationMillis = millis(static function () use ($cache, $clock, $kernel, $twig, $urls, &$built): void {
    renderer(stackScreen($cache, $clock, $kernel, $built), $twig, $clock)->page(Request::create('/'), 'requests', $urls);
});
report('application screen, cache round trips of the stack', $cache->roundTrips(), 'trips', 1, sprintf('(%s; page %.2f ms with Twig)', json_encode($cache->calls, JSON_THROW_ON_ERROR), $applicationMillis), true);
report('application screen, stack readers built', count(array_filter($built, static fn(string $id): bool => $id === StackReaderInterface::class)), 'built', 0, sprintf('(services asked for: %s; without a reader no collector runs)', implode(', ', array_map(static fn(string $id): string => substr((string) strrchr('\\' . $id, '\\'), 1), $built))), true);

$dirs[] = $dir = workDir();
$cache = filesystemCache($dir);
renderer(stackScreen($cache, $clock, $kernel, $built), $twig, $clock)->page(Request::create('/'), 'stack', $urls);
renderer(stackScreen($cache, $clock, $kernel, $built), $twig, $clock)->fragment(Request::create('/'), 'stack', $urls);
$cache->reset();
$html = renderer(stackScreen($cache, $clock, $kernel, $built), $twig, $clock)->page(Request::create('/'), 'stack', $urls)->html;
$cards = substr_count($html, 'data-jm-component=');
report('Stack screen, everything in cache, cache round trips', $cache->roundTrips(), 'trips', 2 * $cards + 2, sprintf('(%d cards; %s)', $cards, json_encode($cache->calls, JSON_THROW_ON_ERROR)), true);

$total = 0.0;
for ($i = 0; $i < SCREEN_RUNS; ++$i) {
    $screen = stackScreen($cache, $clock, $kernel, $built);
    $total += millis(static fn(): array => $screen->blocks(new ScreenRequest($clock->now()->getTimestamp())));
}
report('Stack screen, warm, blocks without Twig, duration', $total / SCREEN_RUNS, 'ms', 15, sprintf('(mean of %d; detection, reading, checks and summary included)', SCREEN_RUNS));

[$slowest, $mostRefreshes] = coldDisplays($clock, $kernel, $twig, null, $dirs);
report('Stack screen, first display on an empty cache, page duration', $slowest, 'ms', 1200, sprintf('(slowest of %d, Twig included)', COLD_RUNS));
report('Stack screen, first display, refreshes until complete', $mostRefreshes, 'refr.', 2, sprintf('(most of %d; no slow collector)', COLD_RUNS), true);
[$slowest, $mostRefreshes] = coldDisplays($clock, $kernel, $twig, CYCLES, $dirs);
report('same, with end of life read offline, page duration', $slowest, 'ms', 1200, sprintf('(slowest of %d, Twig included)', COLD_RUNS));
report('same, with end of life read offline, refreshes until complete', $mostRefreshes, 'refr.', null, '(slow collectors only run in a refresh)');

$web = new WebStack();
$storage = new FixedReader();
$display = static function (CacheItemPoolInterface $cache, string $screen, bool $rest = false) use ($clock, $kernel, $twig, $urls, $web, $storage, &$built): string {
    return renderer(screens($cache, $clock, $kernel, $built, null, $web), $twig, $clock, $storage)->page(Request::create('/', 'GET', $rest ? ['rest' => '1'] : []), $screen, $urls)->html;
};
$readersBuilt = static fn(array $built): int => count(array_filter($built, static fn(string $id): bool => $id === StackReaderInterface::class));

$dirs[] = $dir = workDir();
$cache = filesystemCache($dir);
$display($cache, 'stack');
$display($cache, 'stack', true);
$display($cache, 'messages');
$display($cache, 'messages', true);
$cache->reset();
$web->reset();
$built = [];
$display($cache, 'requests');
report('with Nginx and the queues: application screen, cache round trips', $cache->roundTrips(), 'trips', 1, sprintf('(%s)', json_encode($cache->calls, JSON_THROW_ON_ERROR)), true);
report('with Nginx and the queues: application screen, stack readers built', $readersBuilt($built), 'built', 0, '', true);
report('with Nginx and the queues: application screen, status pages and commands', $web->calls(), 'calls', 0, '', true);

$cache->reset();
$web->reset();
$html = $display($cache, 'messages');
report('Messages, queues in cache, cache round trips', $cache->roundTrips(), 'trips', 2, sprintf('(the badge, then the queues; %s; the design aims at 1 for the queues)', json_encode($cache->calls, JSON_THROW_ON_ERROR)), true);
report('Messages, queues in cache, commands run', count($web->queues->calls), 'calls', 0, str_contains($html, 'data-jm-reading') ? '(still reading)' : '', true);

$dirs[] = $dir = workDir();
$cache = filesystemCache($dir);
$web->reset();
$storage->queries = [];
$html = $display($cache, 'messages');
$pageTrips = $cache->roundTrips();
$pageReads = count($storage->queries);
$pageCommands = count($web->queues->calls);
$cache->reset();
$storage->queries = [];
$restMillis = millis(static function () use ($display, $cache, &$html): void {
    $html = $display($cache, 'messages', true);
});
report('Messages, first page on an empty cache, commands run', $pageCommands, 'calls', 0, sprintf('(%d cache round trips, %d storage reads)', $pageTrips, $pageReads), true);
report('Messages, follow-up (?rest=1), commands run', count($web->queues->calls), 'calls', 1, sprintf('(%.2f ms with Twig; %s)', $restMillis, str_contains($html, 'data-jm-reading') ? 'still reading' : 'complete'), true);
report('Messages, follow-up (?rest=1), cache round trips', $cache->roundTrips(), 'trips', null, sprintf('(%s)', json_encode($cache->calls, JSON_THROW_ON_ERROR)));
report('Messages, follow-up (?rest=1), storage reads added', count($storage->queries), 'reads', null, sprintf('(the same as the page: %d)', $pageReads));

$dirs[] = $dir = workDir();
$cache = filesystemCache($dir);
$display($cache, 'stack');
$display($cache, 'stack', true);
$cache->reset();
$web->reset();
$html = $display($cache, 'stack');
$cards = substr_count($html, 'data-jm-component=');
report('Stack screen with Nginx, everything in cache, cache round trips', $cache->roundTrips(), 'trips', 2 * $cards + 2, sprintf('(%d cards; %s)', $cards, json_encode($cache->calls, JSON_THROW_ON_ERROR)), true);
report('Stack screen with Nginx, everything in cache, status pages and commands', $web->calls(), 'calls', 0, '', true);

$total = 0.0;
for ($i = 0; $i < SCREEN_RUNS; ++$i) {
    $screen = stackScreen($cache, $clock, $kernel, $built, null, $web);
    $total += millis(static fn(): array => $screen->blocks(new ScreenRequest($clock->now()->getTimestamp())));
}
report('Stack screen with Nginx, warm, blocks without Twig, duration', $total / SCREEN_RUNS, 'ms', 15, sprintf('(mean of %d; detection, reading, checks and summary included)', SCREEN_RUNS));

$dirs[] = $dir = workDir();
$mockClock = new MockClock();
[$reader, $registry] = engine(filesystemCache($dir), $mockClock, $kernel, null, $web);
$reader->overview(ReadMode::Page);
$reader->overview(ReadMode::Fragment);
$web->reset();
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
report('dynamic renewal of Server, PHP, Symfony and Nginx, duration', $total / RENEWAL_RUNS, 'ms', 150, sprintf('(mean of %d)', RENEWAL_RUNS));
report('dynamic renewal of Server, PHP, Symfony and Nginx, values read', $renewed / RENEWAL_RUNS, 'calls', 4, '(per renewal; four dynamic collectors)', true);
report('dynamic renewal of Server, PHP, Symfony and Nginx, status pages', $web->pages->calls / RENEWAL_RUNS, 'calls', 1, '(per renewal)', true);
report('dynamic renewal of Server, PHP, Symfony and Nginx, commands', count($web->nginx->calls), 'calls', 0, sprintf('(in %d renewals)', RENEWAL_RUNS), true);

$slowest = 0.0;
$mostRefreshes = 0;
$firstCalls = 0;
for ($i = 0; $i < COLD_RUNS; ++$i) {
    $dirs[] = $dir = workDir();
    $cache = filesystemCache($dir);
    $web->reset();
    $slowest = max($slowest, millis(static function () use ($display, $cache, &$html): void {
        $html = $display($cache, 'stack');
    }));
    $firstCalls = max($firstCalls, $web->calls());
    $refreshes = 0;
    while (str_contains($html, 'data-jm-reading') && $refreshes < 10) {
        $html = $display($cache, 'stack', true);
        ++$refreshes;
    }
    $mostRefreshes = max($mostRefreshes, str_contains($html, 'data-jm-reading') ? PHP_INT_MAX : $refreshes);
}
report('Stack screen with Nginx, first display on an empty cache, page duration', $slowest, 'ms', 1200, sprintf('(slowest of %d, Twig included; %d status page or command calls in the page at most)', COLD_RUNS, $firstCalls));
report('Stack screen with Nginx, first display, follow-ups until complete', $mostRefreshes, 'refr.', null, sprintf('(most of %d; the nginx commands only run in a follow-up)', COLD_RUNS));

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

$mysqlUrl = getenv('JMONITOR_TEST_MYSQL_URL');
if (!is_string($mysqlUrl) || $mysqlUrl === '') {
    echo "MySQL measures skipped: JMONITOR_TEST_MYSQL_URL is not set\n";
    (new Filesystem())->remove($dirs);

    exit;
}

$stackConfig = ['stack' => ['end_of_life' => false, 'symfony' => ['flex' => false]]];
$kernel = new TestKernel('prod', false, $stackConfig, withHttpClient: false, withPublicInternals: true, connections: ['main' => ['url' => $mysqlUrl]]);
$kernel->boot();
$dirs[] = $kernel->getVarDir();
$web->reset();

$dirs[] = $dir = workDir();
$cache = filesystemCache($dir);
[$reader] = engine($cache, $clock, $kernel, null, $web, mysqlDetector($kernel));
$reader->overview(ReadMode::Page);
$reader->overview(ReadMode::Fragment);
$kernel->shutdown();
$kernel->boot();
[, $registry] = engine($cache, $clock, $kernel, null, $web, mysqlDetector($kernel));
$cache->reset();
$web->reset();
$before = serverConnections($mysqlUrl);
$detectMillis = millis(static fn(): array => array_map($registry->status(...), Component::cases()));
$opened = serverConnections($mysqlUrl) - $before - 1;
report('with Nginx and MySQL: detection of all components, warm, cache round trips', $cache->roundTrips(), 'trips', 0, sprintf('(%.2f ms)', $detectMillis), true);
report('with Nginx and MySQL: detection of all components, warm, connections built', (int) $kernel->getContainer()->initialized('doctrine.dbal.main_connection'), 'built', 0, '', true);
report('with Nginx and MySQL: detection of all components, warm, network calls', $opened + $web->calls(), 'calls', 0, sprintf('(%d connections to MySQL, %d status pages or commands)', $opened, $web->calls()), true);

$mysql = mysqlDetector($kernel);
$showStack = static function (CacheItemPoolInterface $cache, bool $rest = false) use ($clock, $kernel, $twig, $urls, $web, $storage, $mysql, &$built): string {
    return renderer(screens($cache, $clock, $kernel, $built, null, $web, $mysql), $twig, $clock, $storage)->page(Request::create('/', 'GET', $rest ? ['rest' => '1'] : []), 'stack', $urls)->html;
};

$dirs[] = $dir = workDir();
$cache = filesystemCache($dir);
$showStack($cache);
$showStack($cache, true);
$cache->reset();
$web->reset();
$before = serverConnections($mysqlUrl);
$html = $showStack($cache);
$opened = serverConnections($mysqlUrl) - $before - 1;
$cards = substr_count($html, 'data-jm-component=');
report('Stack screen with Nginx and MySQL, everything in cache, cache round trips', $cache->roundTrips(), 'trips', 2 * $cards + 2, sprintf('(%d cards; %s)', $cards, json_encode($cache->calls, JSON_THROW_ON_ERROR)), true);
report('Stack screen with Nginx and MySQL, everything in cache, MySQL connections', $opened, 'conn.', 0, sprintf('(%d status pages or commands)', $web->calls()), true);

$total = 0.0;
for ($i = 0; $i < SCREEN_RUNS; ++$i) {
    $screen = stackScreen($cache, $clock, $kernel, $built, null, $web, $mysql);
    $total += millis(static fn(): array => $screen->blocks(new ScreenRequest($clock->now()->getTimestamp())));
}
report('Stack screen with Nginx and MySQL, warm, blocks without Twig, duration', $total / SCREEN_RUNS, 'ms', 15, sprintf('(mean of %d; detection, reading, checks and summary included)', SCREEN_RUNS));

$dirs[] = $dir = workDir();
$mockClock = new MockClock();
[$reader, $registry] = engine(filesystemCache($dir), $mockClock, $kernel, null, $web, $mysql);
$reader->overview(ReadMode::Page);
$reader->overview(ReadMode::Fragment);
$web->reset();
$before = serverConnections($mysqlUrl);
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
$opened = serverConnections($mysqlUrl) - $before - 1;
report('dynamic renewal of Server, PHP, Symfony, Nginx and MySQL, duration', $total / RENEWAL_RUNS, 'ms', 150, sprintf('(mean of %d)', RENEWAL_RUNS));
report('dynamic renewal of Server, PHP, Symfony, Nginx and MySQL, values read', $renewed / RENEWAL_RUNS, 'calls', 5, '(per renewal; five dynamic collectors)', true);
report('dynamic renewal of Server, PHP, Symfony, Nginx and MySQL, connections', $opened / RENEWAL_RUNS, 'conn.', null, '(per renewal, each closed at the end of the read)');

$slowest = 0.0;
$mostRefreshes = 0;
$firstOpened = 0;
for ($i = 0; $i < COLD_RUNS; ++$i) {
    $dirs[] = $dir = workDir();
    $cache = filesystemCache($dir);
    $before = serverConnections($mysqlUrl);
    $slowest = max($slowest, millis(static function () use ($showStack, $cache, &$html): void {
        $html = $showStack($cache);
    }));
    $firstOpened = max($firstOpened, serverConnections($mysqlUrl) - $before - 1);
    $refreshes = 0;
    while (str_contains($html, 'data-jm-reading') && $refreshes < 10) {
        $html = $showStack($cache, true);
        ++$refreshes;
    }
    $mostRefreshes = max($mostRefreshes, str_contains($html, 'data-jm-reading') ? PHP_INT_MAX : $refreshes);
}
report('Stack screen with Nginx and MySQL, first display, empty cache, duration', $slowest, 'ms', 1200, sprintf('(slowest of %d, Twig included; %d connections to MySQL in the page at most)', COLD_RUNS, $firstOpened));
report('Stack screen with Nginx and MySQL, first display, follow-ups until complete', $mostRefreshes, 'refr.', null, sprintf('(most of %d)', COLD_RUNS));
$kernel->shutdown();

$pgsqlUrl = getenv('JMONITOR_TEST_PGSQL_URL');
$redisUrl = getenv('JMONITOR_TEST_REDIS_URL');
if (!is_string($pgsqlUrl) || $pgsqlUrl === '' || !is_string($redisUrl) || $redisUrl === '') {
    echo "PostgreSQL and Redis measures skipped: JMONITOR_TEST_PGSQL_URL or JMONITOR_TEST_REDIS_URL is not set\n";
} else {
    $remoteConfig = ['stack' => $stackConfig['stack'] + ['redis' => ['connections' => ['cache' => $redisUrl]]]];
    $remoteConnections = ['main' => ['url' => $mysqlUrl], 'shop' => ['url' => $pgsqlUrl, 'server_version' => '17']];
    $kernel = new TestKernel('prod', false, $remoteConfig, withHttpClient: false, withPublicInternals: true, connections: $remoteConnections);
    $kernel->boot();
    $dirs[] = $kernel->getVarDir();
    $web->reset();

    $dirs[] = $dir = workDir();
    $cache = filesystemCache($dir);
    [$reader] = engine($cache, $clock, $kernel, null, $web, mysqlDetector($kernel), remoteDetectors($kernel));
    $reader->overview(ReadMode::Page);
    $reader->overview(ReadMode::Fragment);
    $kernel->shutdown();
    $kernel->boot();
    [, $registry] = engine($cache, $clock, $kernel, null, $web, mysqlDetector($kernel), remoteDetectors($kernel));
    $cache->reset();
    $web->reset();
    $mysqlBefore = serverConnections($mysqlUrl);
    $redisBefore = redisConnections($redisUrl);
    $detectMillis = millis(static fn(): array => array_map($registry->status(...), Component::cases()));
    $mysqlOpened = serverConnections($mysqlUrl) - $mysqlBefore - 1;
    $redisOpened = redisConnections($redisUrl) - $redisBefore - 1;
    $container = $kernel->getContainer();
    $connectionsBuilt = (int) $container->initialized('doctrine.dbal.main_connection') + (int) $container->initialized('doctrine.dbal.shop_connection');
    report('with Nginx, MySQL, PostgreSQL and Redis: detection, warm, cache round trips', $cache->roundTrips(), 'trips', 0, sprintf('(%.2f ms)', $detectMillis), true);
    report('with Nginx, MySQL, PostgreSQL and Redis: detection, warm, connections built', $connectionsBuilt, 'built', 0, '(MySQL and PostgreSQL Doctrine connections)', true);
    report('with Nginx, MySQL, PostgreSQL and Redis: detection, warm, network calls', $mysqlOpened + $redisOpened + $web->calls(), 'calls', 0, sprintf('(%d connections to MySQL, %d to Redis, %d status pages or commands; PostgreSQL: no connection built)', $mysqlOpened, $redisOpened, $web->calls()), true);

    $mysql = mysqlDetector($kernel);
    $remote = remoteDetectors($kernel);
    $showStack = static function (CacheItemPoolInterface $cache, bool $rest = false) use ($clock, $kernel, $twig, $urls, $web, $storage, &$mysql, &$remote, &$built): string {
        return renderer(screens($cache, $clock, $kernel, $built, null, $web, $mysql, $remote), $twig, $clock, $storage)->page(Request::create('/', 'GET', $rest ? ['rest' => '1'] : []), 'stack', $urls)->html;
    };
    $dirs[] = $dir = workDir();
    $cache = filesystemCache($dir);
    $showStack($cache);
    $showStack($cache, true);
    $kernel->shutdown();
    $kernel->boot();
    $mysql = mysqlDetector($kernel);
    $remote = remoteDetectors($kernel);
    $cache->reset();
    $web->reset();
    $mysqlBefore = serverConnections($mysqlUrl);
    $redisBefore = redisConnections($redisUrl);
    $html = $showStack($cache);
    $mysqlOpened = serverConnections($mysqlUrl) - $mysqlBefore - 1;
    $redisOpened = redisConnections($redisUrl) - $redisBefore - 1;
    $cards = substr_count($html, 'data-jm-component=');
    report('Stack screen with Nginx, MySQL, PostgreSQL and Redis, in cache, cache round trips', $cache->roundTrips(), 'trips', 2 * $cards + 2, sprintf('(%d cards; %s)', $cards, json_encode($cache->calls, JSON_THROW_ON_ERROR)), true);
    report('Stack screen with Nginx, MySQL, PostgreSQL and Redis, in cache, connections', $mysqlOpened + $redisOpened + (int) $kernel->getContainer()->initialized('doctrine.dbal.shop_connection'), 'conn.', 0, sprintf('(MySQL %d, Redis %d, PostgreSQL connection built %s; %d status pages or commands)', $mysqlOpened, $redisOpened, $kernel->getContainer()->initialized('doctrine.dbal.shop_connection') ? 'yes' : 'no', $web->calls()), true);

    $web->reset();
    [$millis, $renewed] = renewals($kernel, $web, $mysql, ['redis' => $remote['redis']], $dirs);
    report('dynamic renewal of Server, PHP, Symfony, Nginx, MySQL and Redis, duration', $millis, 'ms', 150, sprintf('(mean of %d)', RENEWAL_RUNS));
    report('dynamic renewal of Server, PHP, Symfony, Nginx, MySQL and Redis, values read', $renewed, 'calls', 6, '(per renewal; six dynamic collectors)', true);
    [$millis, $renewed] = renewals($kernel, $web, $mysql, $remote, $dirs);
    report('same, with PostgreSQL, duration', $millis, 'ms', 150, sprintf('(mean of %d)', RENEWAL_RUNS));
    report('same, with PostgreSQL, values read', $renewed, 'calls', null, '(per renewal)');
    $kernel->shutdown();
}

$frozen = new FrozenServer();
$kernel = new TestKernel('prod', false, $stackConfig, withHttpClient: false, withPublicInternals: true, connections: ['frozen' => ['url' => sprintf('mysql://jmonitor:jmonitor@127.0.0.1:%d/jmonitor', $frozen->port)]]);
$kernel->boot();
$dirs[] = $kernel->getVarDir();
$shifted = new ShiftedClock();
$dirs[] = $dir = workDir();
[$reader, $registry] = engine(filesystemCache($dir), $shifted, $kernel, null, null, mysqlDetector($kernel));
$frozenDisplay = static function () use ($reader, $registry, $frozen): array {
    $registry->reset();
    $millis = millis(static fn(): StackState => $reader->overview(ReadMode::Fragment));

    return [$millis, $frozen->attempts()];
};
$waited = 0.0;
$displays = 0;
do {
    [$waited, $tried] = $frozenDisplay();
    ++$displays;
} while ($tried === 0 && $displays < 5);
$waitEnded = microtime(true);
$waits = [];
$slowest = 0.0;
for ($i = 1; $i <= DISPLAYS; ++$i) {
    $shifted->shift = $i * 59 / DISPLAYS - (microtime(true) - $waitEnded);
    [$millis, $tried] = $frozenDisplay();
    if ($tried > 0) {
        $waits[] = sprintf('%.0f ms', $millis);
    } else {
        $slowest = max($slowest, $millis);
    }
}
$shifted->shift = 61 - (microtime(true) - $waitEnded);
[$again, $retried] = $frozenDisplay();
report('frozen MySQL server, cold cache, the first display that tries, duration', $waited, 'ms', null, sprintf('(display %d; the read waits the 10 s set while the connection opens)', $displays));
report(sprintf('frozen MySQL server, %d displays over the next 59 s, displays that wait', DISPLAYS), count($waits), 'disp.', 0, sprintf('(%s)', implode(', ', $waits)), true);
report(sprintf('frozen MySQL server, %d displays over the next 59 s, slowest of the others', DISPLAYS), $slowest, 'ms', 50);
report('frozen MySQL server, display 61 s after the first wait, duration', $again, 'ms', null, sprintf('(%d connection)', $retried));
$kernel->shutdown();

(new Filesystem())->remove($dirs);
