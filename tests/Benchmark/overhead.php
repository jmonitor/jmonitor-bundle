<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ExceptionsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\UsersScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\Section\CacheSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\CommandsSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\HttpSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\Section\TasksSection;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Flush\LabelSync;
use Jmonitor\JmonitorBundle\Recording\Cache\RecordingAdapter;
use Jmonitor\JmonitorBundle\Recording\CommandRecorder;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\HttpClient\CallRecorder;
use Jmonitor\JmonitorBundle\Recording\HttpClient\RecordingHttpClient;
use Jmonitor\JmonitorBundle\Recording\MessengerRecorder;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Dbal\KeysTable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const CALLS = 100_000;
const UNITS = 5_000;
const FLUSH_ENTRIES = 10_000;
const FLUSH_ROUTES = 50;
const SQL_PER_REQUEST = 30;

/** routes, distinct queries, requests in the ten-second slot, queries per request, users, called hosts, cache pools */
const TYPICAL = [30, 50, 1_000, 10, 20, 5, 3];
const LOADED = [150, 200, 10_000, 20, 200, 50, 20];
/** routes, distinct queries, couples of a route and a repeated query, users, called hosts, cache pools, exception classes, in each bucket of every period */
const DISPLAYED = [150, 200, 100, 200, 50, 20, 20];
/** commands, message classes, scheduled tasks, in each bucket of every period */
const DISPLAYED_BACKGROUND = [100, 100, 100];
const DISPLAY_RUNS = 5;
/** Seconds after which five one-minute buckets have left the one-hour window, and no bucket of the other periods theirs. */
const PURGE_DELAY = 420;
const QUERIES_PER_ROUTE = 5;
const EXCEPTION_CLASSES = 5;

function workDir(): string
{
    $dir = sys_get_temp_dir() . '/jmonitor-bench-' . bin2hex(random_bytes(6));
    mkdir($dir);

    return $dir;
}

function removeDir(string $dir): void
{
    (new Filesystem())->remove($dir);
}

function report(string $measure, float $value, string $unit, ?float $target, string $note = ''): void
{
    printf(
        "%-62s %10.2f %-5s %s%s\n",
        $measure . ':',
        $value,
        $unit,
        $target === null ? '(no target)' : sprintf('(target < %s %s: %s)', $target, $unit, $value < $target ? 'met' : 'MISSED'),
        $note === '' ? '' : ' ' . $note,
    );
}

function microsPerCall(callable $operation): float
{
    $start = hrtime(true);
    for ($i = 0; $i < CALLS; ++$i) {
        $operation();
    }

    return (hrtime(true) - $start) / 1000 / CALLS;
}

/**
 * @return list<string>
 */
function sqlTexts(int $count): array
{
    $texts = [];
    for ($i = 0; $i < $count; ++$i) {
        $texts[] = sprintf('SELECT t0.id, t0.name, t0.created_at FROM table_%d t0 WHERE t0.owner_id = ? AND t0.status = ? ORDER BY t0.created_at DESC LIMIT 20', $i);
    }

    return $texts;
}

function poolName(int $index): string
{
    return $index === 0 ? 'cache.app' : 'app.pool_' . $index;
}

function messageClass(int $index): string
{
    return 'App\\Message\\Message' . $index;
}

function exceptionClass(int $index): string
{
    return 'App\Exception\Failure' . $index;
}

function sqlRecorder(BufferDirectory $directory, ClockInterface $clock): Recorder
{
    return new Recorder(new FileBuffer($directory, 10, $clock), null, null, new QueryDigester(new LabelStore($directory)));
}

/**
 * @param list<string> $texts
 */
function openUnitWithQueries(Recorder $recorder, array $texts, float $startedAt): int
{
    $token = $recorder->begin(Entry::TYPE_REQUEST, $startedAt);
    foreach ($texts as $k => $sql) {
        $recorder->addSql($sql, 0.2 + $k * 0.037, SqlDialect::Sqlite);
    }

    return $token;
}

function endUnit(Recorder $recorder, int $token): void
{
    $recorder->end($token, 'GET app_hello', 12.5, ['status' => 200, 'memory' => 2_097_152]);
}

function clearSlots(BufferDirectory $directory): void
{
    foreach (glob($directory->path() . '/*.ndjson') ?: [] as $file) {
        unlink($file);
    }
}

/**
 * Time spent in end() alone, for units whose queries already have their label file.
 *
 * @param bool $fresh a new recorder, digester and buffer directory for each unit, as in PHP-FPM
 */
function endMicros(bool $fresh): float
{
    $dir = workDir();
    $clock = new NativeClock();
    $texts = sqlTexts(SQL_PER_REQUEST);
    $directory = new BufferDirectory($dir, 'bench');
    $recorder = sqlRecorder($directory, $clock);
    endUnit($recorder, openUnitWithQueries($recorder, $texts, microtime(true)));

    $elapsed = 0;
    for ($unit = 0; $unit < UNITS; ++$unit) {
        if ($fresh) {
            $directory = new BufferDirectory($dir, 'bench');
            $recorder = sqlRecorder($directory, $clock);
        }
        $token = openUnitWithQueries($recorder, $texts, microtime(true));

        $start = hrtime(true);
        endUnit($recorder, $token);
        $elapsed += hrtime(true) - $start;

        if ($unit % 1000 === 999) {
            clearSlots($directory);
        }
    }
    removeDir($dir);

    return $elapsed / 1000 / UNITS;
}

function lineBytes(): int
{
    $dir = workDir();
    $directory = new BufferDirectory($dir, 'bench');
    $recorder = sqlRecorder($directory, new NativeClock());
    endUnit($recorder, openUnitWithQueries($recorder, sqlTexts(SQL_PER_REQUEST), microtime(true)));
    $lines = [];
    foreach (glob($directory->path() . '/*.ndjson') ?: [] as $file) {
        array_push($lines, ...(file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: []));
    }
    removeDir($dir);

    if (\count($lines) !== 1 || !str_contains($lines[0], '"sql_n":' . SQL_PER_REQUEST)) {
        throw new RuntimeException('Expected one line carrying the SQL queries');
    }

    return \strlen($lines[0]);
}

/**
 * Records one ten-second slot of requests the way the application does, sampling under pressure included.
 *
 * @param array{int, int, int, int, int, int, int} $dataSet
 *
 * @return int units recorded under pressure that kept their queries, at weight 10
 */
function fillSlot(BufferDirectory $directory, ClockInterface $clock, array $dataSet): int
{
    [$routes, $queries, $requests, $perRequest, $users, $hosts, $pools] = $dataSet;
    $texts = sqlTexts($queries);
    $start = (float) $clock->now()->getTimestamp();
    $recorder = sqlRecorder($directory, $clock);

    for ($i = 0; $i < $requests; ++$i) {
        $route = $i % $routes;
        $token = $recorder->begin(Entry::TYPE_REQUEST, $start + $i * 10 / $requests);
        for ($k = 0; $k < $perRequest; ++$k) {
            $recorder->addSql($texts[(($i * $perRequest + $k) * 7) % $queries], 0.1 + (($i + $k) % 40) * 0.05, SqlDialect::Sqlite);
        }
        if ($route % 10 === 0) {
            for ($n = 0; $n < 12; ++$n) {
                $recorder->addSql($texts[$route % $queries], 0.15, SqlDialect::Sqlite);
            }
        }
        $recorder->add($token, Entry::TYPE_USER, 'user' . ($i % $users) . '@example.com');
        $recorder->add($token, Entry::TYPE_HTTP, 'api' . ($i % $hosts) . '.example.com', 20.0 + ($i % 30), $i % 40 === 0 ? ['c5' => 1] : []);
        $recorder->add($token, Entry::TYPE_CACHE, poolName($i % $pools), 0.0, ['h' => 1]);
        $recorder->add($token, Entry::TYPE_CACHE, poolName(($i + 1) % $pools), 0.0, ['mi' => 1, 'w' => 1]);
        $failed = $i % 50 === 0;
        if ($failed) {
            $recorder->add($token, Entry::TYPE_EXCEPTION, exceptionClass(intdiv($i, 50) % EXCEPTION_CLASSES));
        }
        $recorder->end($token, 'GET route_' . $route, 5.0 + ($i * 37) % 1500, ['status' => $failed ? 500 : 200, 'memory' => 2_097_152 + ($i % 64) * 65_536]);
    }

    $sampled = 0;
    foreach (glob($directory->path() . '/*.ndjson') ?: [] as $file) {
        foreach (file($file) ?: [] as $line) {
            $sampled += (int) str_contains($line, '"cw":');
        }
    }

    return $sampled;
}

/**
 * @param callable(BufferDirectory, ClockInterface, FileBuffer): void $fill
 * @param bool $onExit the flush that ends a command or a worker, current slot included
 *
 * @return array{float, int} milliseconds, rows written
 */
function flushMillis(Connection $connection, callable $fill, bool $onExit = false): array
{
    $dir = workDir();
    $clock = new MockClock('@1000');
    $directory = new BufferDirectory($dir, 'bench');
    $buffer = new FileBuffer($directory, 10, $clock);
    $lock = new FlushLock($directory, 10, $clock);
    $hotKeys = new HotKeys($directory, $clock);
    $storage = new DbalStorage($connection, 31_536_000);
    $flusher = new Flusher(
        $buffer,
        new Aggregator(500, $hotKeys),
        $storage,
        $lock,
        $clock,
        null,
        300,
        $hotKeys,
        null,
        new LabelSync(new LabelStore($directory), $hotKeys),
    );

    $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);
    $connection->executeStatement('DROP TABLE IF EXISTS ' . KeysTable::NAME);
    $storage->setUp();
    $lock->markPurged();

    $fill($directory, $clock, $buffer);
    if (!$onExit) {
        $clock->sleep(11);
    }

    $start = hrtime(true);
    $flushed = $onExit ? $flusher->flushOnExit() : $flusher->flush();
    $elapsed = (hrtime(true) - $start) / 1_000_000;
    $failure = $flusher->lastFailure();
    removeDir($dir);

    if (!$flushed) {
        throw new RuntimeException('Flush did not run', 0, $failure);
    }

    return [$elapsed, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . AggregatesTable::NAME)];
}

function requestsOnly(BufferDirectory $directory, ClockInterface $clock, FileBuffer $buffer): void
{
    for ($i = 0; $i < FLUSH_ENTRIES; ++$i) {
        $buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET route_' . ($i % FLUSH_ROUTES), 1000.0 + $i % 10, 20.0 + $i % 200, ['status' => 200, 'memory' => 2_097_152]));
    }
}

function oneCommand(BufferDirectory $directory, ClockInterface $clock, FileBuffer $buffer): void
{
    $buffer->append(new Entry(Entry::TYPE_COMMAND, 'app:import', (float) $clock->now()->getTimestamp(), 35.0, ['exit' => 0, 'memory' => 8_388_608]));
}

/**
 * @return array{float, int} milliseconds, units that kept their queries under pressure
 */
function aggregateMillis(): array
{
    $dir = workDir();
    $clock = new MockClock('@1000');
    $directory = new BufferDirectory($dir, 'bench');
    $buffer = new FileBuffer($directory, 10, $clock);
    $aggregator = new Aggregator(500, new HotKeys($directory, $clock));

    $sampled = fillSlot($directory, $clock, LOADED);
    $clock->sleep(11);

    $aggregates = [];
    $start = hrtime(true);
    $buffer->drain(static function (iterable $entries) use ($aggregator, &$aggregates): void {
        $aggregates = $aggregator->aggregate($entries);
    });
    $elapsed = (hrtime(true) - $start) / 1_000_000;
    removeDir($dir);

    if ($aggregates === []) {
        throw new RuntimeException('Nothing was aggregated');
    }

    return [$elapsed, $sampled];
}

/**
 * Stores the data set in each of the sixty buckets of every period, as a week of flushes would.
 *
 * @param array{int, int, int, int, int, int, int} $dataSet
 *
 * @return int rows stored, every period included
 */
function fillPeriods(Connection $connection, int $now, array $dataSet): int
{
    [$routes, $queries, $repeated, $users, $hosts, $pools, $classes] = $dataSet;
    $ids = [];
    $labels = [];
    foreach (sqlTexts($queries) as $text) {
        $ids[] = $id = substr(hash('xxh3', $text), 0, 16);
        $labels[$id] = $text;
    }

    $units = [];
    for ($minute = 0; $minute < 60; ++$minute) {
        $startedAt = (float) ($now - $minute * 60);
        for ($route = 0; $route < $routes; ++$route) {
            $children = [];
            for ($k = 0; $k < QUERIES_PER_ROUTE; ++$k) {
                $children[] = new Entry(Entry::TYPE_QUERY, $ids[($route * QUERIES_PER_ROUTE + $k) % $queries], $startedAt, 0.4 + ($minute + $k) % 7 * 0.3, ['n' => 1 + $k, 'm' => 0.4]);
            }
            if ($route < $repeated) {
                $children[] = new Entry(Entry::TYPE_NPLUS1, $ids[$route * QUERIES_PER_ROUTE % $queries], $startedAt, 1.8, ['n' => 12]);
            }
            $children[] = new Entry(Entry::TYPE_USER, 'user' . (($minute * $routes + $route) % $users) . '@example.com', $startedAt, 0.0, ['n' => 1]);
            $children[] = new Entry(Entry::TYPE_HTTP, 'api' . (($minute + $route) % $hosts) . '.example.com', $startedAt, 24.0, ['n' => 2, 'm' => 15.0] + ($route % 40 === 0 ? ['c5' => 1] : []));
            $children[] = new Entry(Entry::TYPE_CACHE, poolName($route % $pools), $startedAt, 0.0, ['n' => 3, 'h' => 2, 'mi' => 1, 'w' => 1]);
            $children[] = new Entry(Entry::TYPE_SENT, messageClass($route % DISPLAYED_BACKGROUND[1]), $startedAt, 0.0, ['n' => 1]);
            $failed = $route % 10 === 0;
            if ($failed) {
                $children[] = new Entry(Entry::TYPE_EXCEPTION, exceptionClass(($minute + $route) % $classes), $startedAt, 0.0, ['n' => 1]);
            }
            $units[] = new Entry(Entry::TYPE_REQUEST, 'GET route_' . $route, $startedAt, 5.0 + ($minute * 37 + $route) % 1500, [
                'status' => $failed ? 500 : 200,
                'memory' => 2_097_152 + ($route % 64) * 65_536,
                'sql_n' => 15,
                'sql_ms' => 4.5,
                'http_n' => 2,
                'http_ms' => 24.0,
            ], $children);
        }
        [$commands, $messages, $tasks] = DISPLAYED_BACKGROUND;
        for ($i = 0; $i < $commands; ++$i) {
            $units[] = new Entry(Entry::TYPE_COMMAND, 'app:command-' . $i, $startedAt, 50.0 + ($minute * 13 + $i) % 900, ['exit' => $i % 10 === 0 ? 1 : 0, 'memory' => 4_194_304]);
        }
        for ($i = 0; $i < $messages; ++$i) {
            $units[] = new Entry(Entry::TYPE_MESSAGE, messageClass($i), $startedAt, 8.0 + ($minute * 7 + $i) % 300, ['memory' => 4_194_304] + ($i % 10 === 0 ? ['failed' => 1] : []));
        }
        for ($i = 0; $i < $tasks; ++$i) {
            $units[] = new Entry(Entry::TYPE_TASK, (new TaskKey('default', 'task-' . $i, 'app:task-' . $i, 'every minute'))->toString(), $startedAt, 20.0 + ($minute * 11 + $i) % 600, $i % 10 === 0 ? ['failed' => 1] : []);
        }
    }

    $storage = new DbalStorage($connection, 31_536_000);
    $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);
    $connection->executeStatement('DROP TABLE IF EXISTS ' . KeysTable::NAME);
    $storage->setUp();

    $hour = array_filter((new Aggregator(500))->aggregate($units), static fn(Aggregate $aggregate): bool => $aggregate->period === Period::OneHour);
    foreach (Period::cases() as $period) {
        $aggregates = [];
        foreach ($hour as $aggregate) {
            $age = intdiv(Period::OneHour->bucketFor($now) - $aggregate->bucket, Period::OneHour->bucketSeconds());
            $aggregates[] = new Aggregate($period, $period->bucketFor($now) - $age * $period->bucketSeconds(), $aggregate->type, $aggregate->key, $aggregate->count, $aggregate->sum, $aggregate->max);
        }
        $storage->store($aggregates, $now, $labels);
    }
    refreshStatistics($connection);

    return (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . AggregatesTable::NAME);
}

/** SQLite is left without statistics, as in an application that never runs ANALYZE. */
function refreshStatistics(Connection $connection): void
{
    $platform = $connection->getDatabasePlatform();
    foreach ([AggregatesTable::NAME, KeysTable::NAME] as $table) {
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->fetchAllAssociative('ANALYZE TABLE ' . $table);
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement('ANALYZE ' . $table);
        }
    }
}

/**
 * @return array{float, int} milliseconds of the purge that follows the fill by PURGE_DELAY, rows deleted
 */
function purgeMillis(Connection $connection, int $now): array
{
    $count = static fn(): int => (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . AggregatesTable::NAME);
    $storage = new DbalStorage($connection, 31_536_000);
    $before = $count();

    $start = hrtime(true);
    $storage->purge($now + PURGE_DELAY);
    $elapsed = (hrtime(true) - $start) / 1_000_000;

    return [$elapsed, $before - $count()];
}

/**
 * @return array{float, int} median milliseconds of the builds of the screen on its default sort, rows shown
 */
function displayMillis(Connection $connection, int $now, StorageScreenInterface|SectionInterface $screen, Period $period = Period::OneHour): array
{
    $reader = new DbalReader($connection);
    $sorts = $screen->sorts();
    $sort = (string) array_key_first($sorts);

    $timings = [];
    $rows = 0;
    for ($run = 0; $run < DISPLAY_RUNS; ++$run) {
        $start = hrtime(true);
        $view = $screen instanceof SectionInterface ? $screen->build($reader, $period, $now, $sort, $sorts[$sort], new RaisedChecks([], [], $period)) : $screen->build($reader, $period, $now, $sort, $sorts[$sort]);
        $timings[] = (hrtime(true) - $start) / 1_000_000;
        $rows = array_sum(array_map(static fn(Table $table): int => \count($table->rows), $view->tables));
    }
    sort($timings);

    return [$timings[intdiv(DISPLAY_RUNS, 2)], $rows];
}

/**
 * @return array{float, int} median milliseconds of the builds of the whole screen on its default sorts, rows shown
 */
function composedDisplayMillis(Connection $connection, int $now, ComposedScreen $screen): array
{
    $reader = new DbalReader($connection);
    $builder = new ScreenBuilder($reader, true, new MockClock('@' . $now));

    $timings = [];
    for ($run = 0; $run < DISPLAY_RUNS; ++$run) {
        $start = hrtime(true);
        $builder->create($screen, Period::OneHour->label(), null, null);
        $timings[] = (hrtime(true) - $start) / 1_000_000;
    }
    sort($timings);

    $rows = 0;
    foreach ($screen->sections() as $section) {
        $sorts = $section->sorts();
        $sort = (string) array_key_first($sorts);
        $view = $section->build($reader, Period::OneHour, $now, $sort, $sorts[$sort], new RaisedChecks([], [], Period::OneHour));
        $rows += array_sum(array_map(static fn(Table $table): int => \count($table->rows), $view->tables));
    }

    return [$timings[intdiv(DISPLAY_RUNS, 2)], $rows];
}

$dir = workDir();
$clock = new NativeClock();
$buffer = new FileBuffer(new BufferDirectory($dir, 'bench'), 10, $clock);
$entry = new Entry(Entry::TYPE_REQUEST, 'GET app_hello', microtime(true), 12.5, ['status' => 200, 'memory' => 2_097_152]);
report('append, per call', microsPerCall(static fn() => $buffer->append($entry)), 'µs', null);
removeDir($dir);

$dir = workDir();
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dir . '/bench.sqlite']);
$directory = new BufferDirectory($dir, 'bench');
$lock = new FlushLock($directory, 3600, $clock);
$lock->markPurged();
touch($directory->path() . '/last-flush');
$buffer = new FileBuffer($directory, 10, $clock);
$flusher = new Flusher($buffer, new Aggregator(500), new DbalStorage($connection, 31_536_000), $lock, $clock, null, 300);
$recorder = new RequestRecorder(new Recorder($buffer), $flusher);

$kernel = new class implements HttpKernelInterface {
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
};
$request = Request::create('/hello/world');
$request->attributes->set('_route', 'app_hello');
$requestEvent = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
$terminateEvent = new TerminateEvent($kernel, $request, new Response());

$requestPath = microsPerCall(static fn() => $recorder->onRequest($requestEvent));
$wholeRequest = microsPerCall(static function () use ($recorder, $requestEvent, $terminateEvent): void {
    $recorder->onRequest($requestEvent);
    $recorder->onTerminate($terminateEvent);
    $recorder->onTerminateFlush($terminateEvent);
});
report('request path, no SQL', $requestPath, 'µs', 50);
report('after response, no SQL', $wholeRequest - $requestPath, 'µs', 200);

$sqlRecorder = sqlRecorder($directory, $clock);
$sqlRequestRecorder = new RequestRecorder($sqlRecorder, $flusher);
$texts = sqlTexts(SQL_PER_REQUEST);
$sqlRecorder->begin(Entry::TYPE_REQUEST, microtime(true));
$call = 0;
report('addSql(), per call in an open unit', microsPerCall(static function () use ($sqlRecorder, $texts, &$call): void {
    $sqlRecorder->addSql($texts[$call++ % SQL_PER_REQUEST], 0.25, SqlDialect::Sqlite);
}), 'µs', 2);
$sqlRecorder->reset();
report(sprintf('request path, %d SQL queries', SQL_PER_REQUEST), microsPerCall(static function () use ($sqlRequestRecorder, $sqlRecorder, $requestEvent, $texts): void {
    $sqlRequestRecorder->onRequest($requestEvent);
    foreach ($texts as $k => $sql) {
        $sqlRecorder->addSql($sql, 0.2 + $k * 0.037, SqlDialect::Sqlite);
    }
    $sqlRecorder->reset();
}), 'µs', 100);
$connection->close();
removeDir($dir);

report(sprintf('end(), %d distinct queries, new digester and directory (FPM)', SQL_PER_REQUEST), endMicros(true), 'µs', 300);
report(sprintf('end(), %d distinct queries, reused digester (worker)', SQL_PER_REQUEST), endMicros(false), 'µs', 100);
report(sprintf('buffer line, %d distinct queries', SQL_PER_REQUEST), lineBytes(), 'bytes', 1200);
$call = 0;
report('normalize(), per distinct text', microsPerCall(static function () use ($texts, &$call): void {
    SqlNormalizer::normalize($texts[$call++ % SQL_PER_REQUEST], SqlDialect::MySql);
}), 'µs', null);

$dir = workDir();
$httpRecorder = new Recorder(new FileBuffer(new BufferDirectory($dir, 'bench'), 10, $clock));
$httpRecorder->begin(Entry::TYPE_REQUEST, microtime(true));
$mockResponse = static fn(): MockResponse => new MockResponse('{"ok":true}', ['total_time' => 0.012]);
$bareClient = new MockHttpClient($mockResponse);
$recordingClient = new RecordingHttpClient(new MockHttpClient($mockResponse), new CallRecorder($httpRecorder));
$bareCall = static fn() => $bareClient->request('GET', 'https://api.example.com/v1/items?page=2')->getContent();
$recordedCall = static fn() => $recordingClient->request('GET', 'https://api.example.com/v1/items?page=2')->getContent();
microsPerCall($bareCall);
microsPerCall($recordedCall);
$bareMicros = microsPerCall($bareCall);
report('outgoing call, recording overhead', microsPerCall($recordedCall) - $bareMicros, 'µs', 20, sprintf('(%.2f µs per call without recording)', $bareMicros));
$httpRecorder->reset();
removeDir($dir);

$dir = workDir();
$cacheRecorder = new Recorder(new FileBuffer(new BufferDirectory($dir, 'bench'), 10, $clock));
$cacheRecorder->begin(Entry::TYPE_REQUEST, microtime(true));
$barePool = new ArrayAdapter(0, false);
$barePool->save($barePool->getItem('item')->set('value'));
$recordedPool = new RecordingAdapter($barePool, $cacheRecorder, 'cache.app');
$bareRead = static fn() => $barePool->getItem('item');
$recordedRead = static fn() => $recordedPool->getItem('item');
microsPerCall($bareRead);
microsPerCall($recordedRead);
$bareMicros = microsPerCall($bareRead);
report('cache read, recording overhead', microsPerCall($recordedRead) - $bareMicros, 'µs', 1, sprintf('(%.2f µs per getItem() without recording)', $bareMicros));
$cacheRecorder->reset();
removeDir($dir);

$dir = workDir();
$commandRecorder = new CommandRecorder(new Recorder(new FileBuffer(new BufferDirectory($dir, 'bench'), 10, $clock)), null);
$command = new Command('app:import');
$commandEvent = new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput());
$commandTerminateEvent = new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), 0);
report('empty command unit, COMMAND to TERMINATE, no flush', microsPerCall(static function () use ($commandRecorder, $commandEvent, $commandTerminateEvent): void {
    $commandRecorder->onCommand($commandEvent);
    $commandRecorder->onTerminate($commandTerminateEvent);
}), 'µs', null);
removeDir($dir);

$dir = workDir();
$messengerRecorder = new MessengerRecorder(new Recorder(new FileBuffer(new BufferDirectory($dir, 'bench'), 10, $clock)), null);
$envelope = new Envelope(new stdClass());
$receivedEvent = new WorkerMessageReceivedEvent($envelope, 'async');
$handledEvent = new WorkerMessageHandledEvent($envelope, 'async');
report('message unit, received to handled, no flush', microsPerCall(static function () use ($messengerRecorder, $receivedEvent, $handledEvent): void {
    $messengerRecorder->onReceived($receivedEvent);
    $messengerRecorder->onHandled($handledEvent);
}), 'µs', null);
removeDir($dir);

[$aggregateMs, $sampled] = aggregateMillis();
report(sprintf('read and aggregate %d units, no database', LOADED[2]), $aggregateMs, 'ms', 300, sprintf('(%d kept their queries under sampling)', $sampled));

$sqlitePath = tempnam(sys_get_temp_dir(), 'jmonitor_bench_') ?: ':memory:';
$sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $sqlitePath]);
report('flush 10k requests, no SQL (sqlite)', flushMillis($sqlite, 'requestsOnly')[0], 'ms', 50);
$sqlite->close();
@unlink($sqlitePath);

$now = 1_700_003_600;
$loadedPath = tempnam(sys_get_temp_dir(), 'jmonitor_bench_') ?: ':memory:';
$bases = ['sqlite' => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $loadedPath])];
foreach (['mysql' => 'JMONITOR_TEST_MYSQL_URL', 'pgsql' => 'JMONITOR_TEST_PGSQL_URL'] as $name => $variable) {
    $url = getenv($variable);
    if (!\is_string($url) || $url === '') {
        printf("%s is not set: %s measures skipped\n", $variable, $name);

        continue;
    }

    $bases[$name] = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url));
}

if (isset($bases['mysql'])) {
    report('flush 10k requests, no SQL (mysql)', flushMillis($bases['mysql'], 'requestsOnly')[0], 'ms', 50);
    [$milliseconds, $rows] = flushMillis($bases['mysql'], 'oneCommand', true);
    report('exit flush, one command line (mysql)', $milliseconds, 'ms', null, sprintf('(%d rows written)', $rows));
    foreach (['typical' => [TYPICAL, 100], 'loaded' => [LOADED, 500]] as $name => [$dataSet, $target]) {
        $sampled = 0;
        [$milliseconds, $rows] = flushMillis($bases['mysql'], static function (BufferDirectory $directory, ClockInterface $clock) use ($dataSet, &$sampled): void {
            $sampled = fillSlot($directory, $clock, $dataSet);
        });
        report(
            sprintf('flush %s, %d routes, %d queries, %d users, %d hosts, %d pools (mysql)', $name, $dataSet[0], $dataSet[1], $dataSet[4], $dataSet[5], $dataSet[6]),
            $milliseconds,
            'ms',
            $target,
            sprintf('(%d requests, %d kept their queries under sampling, %d rows written)', $dataSet[2], $sampled, $rows),
        );
    }
}

foreach ($bases as $name => $connection) {
    $stored = fillPeriods($connection, $now, DISPLAYED);
    $checks = new ApplicationChecks([new ServerErrorsCheck(), new SlowRequestsCheck(1000), new SlowQueriesCheck(100), new RepeatedQueriesCheck(10)]);
    foreach ([new RequestsScreen($checks, 1000), new QueriesScreen(true, $checks, 100, 10), new HttpSection(true, $checks), new CacheSection(true, $checks), new MessagesSection(true, true, null, $checks), new TasksSection(true, new DeclaredTasks(), $checks), new CommandsSection(true, $checks), new ExceptionsScreen(true, $checks), new UsersScreen(true, $checks, 1000)] as $screen) {
        [$milliseconds, $rows] = displayMillis($connection, $now, $screen);
        report(sprintf('display, %s, loaded database (%s)', $screen->id(), $name), $milliseconds, 'ms', 100, sprintf('(%d rows shown, %d rows stored)', $rows, $stored));
        if ($screen instanceof RequestsScreen || $screen instanceof QueriesScreen) {
            [$milliseconds, $rows] = displayMillis($connection, $now, $screen, Period::SevenDays);
            report(sprintf('display, %s, last 7 days, loaded database (%s)', $screen->id(), $name), $milliseconds, 'ms', 100, sprintf('(%d rows shown, %d rows stored)', $rows, $stored));
        }
    }
    foreach ([
        new ComposedScreen('cache-http', 'Cache & HTTP', '', Space::Application, [new CacheSection(true, $checks), new HttpSection(true, $checks)]),
        new ComposedScreen('background', 'Background', '', Space::Background, [new MessagesSection(true, true, null, $checks), new TasksSection(true, new DeclaredTasks(), $checks), new CommandsSection(true, $checks)]),
    ] as $screen) {
        [$milliseconds, $rows] = composedDisplayMillis($connection, $now, $screen);
        report(sprintf('display, %s screen, loaded database (%s)', $screen->id(), $name), $milliseconds, 'ms', 100 * \count($screen->sections()), sprintf('(%d rows shown, %d rows stored)', $rows, $stored));
    }
    [$milliseconds, $deleted] = purgeMillis($connection, $now);
    report(sprintf('purge, five one-minute buckets, loaded database (%s)', $name), $milliseconds, 'ms', null, sprintf('(%d of %d rows deleted)', $deleted, $stored));
    $connection->close();
}
@unlink($loadedPath);
