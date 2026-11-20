<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Flush\LabelSync;
use Jmonitor\JmonitorBundle\Recording\Entry;
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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const CALLS = 100_000;
const UNITS = 5_000;
const FLUSH_ENTRIES = 10_000;
const FLUSH_ROUTES = 50;
const SQL_PER_REQUEST = 30;

/** routes, distinct queries, requests in the ten-second slot, queries per request */
const TYPICAL = [30, 50, 1_000, 10];
const LOADED = [150, 200, 10_000, 20];
/** routes, distinct queries, couples of a route and a repeated query, in each bucket of the one-hour period */
const DISPLAYED = [150, 200, 100];
const DISPLAY_RUNS = 5;
const QUERIES_PER_ROUTE = 5;

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
 * @param array{int, int, int, int} $dataSet
 *
 * @return int units recorded under pressure that kept their queries, at weight 10
 */
function fillSlot(BufferDirectory $directory, ClockInterface $clock, array $dataSet): int
{
    [$routes, $queries, $requests, $perRequest] = $dataSet;
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
        $recorder->end($token, 'GET route_' . $route, 5.0 + ($i * 37) % 1500, ['status' => $i % 50 === 0 ? 500 : 200, 'memory' => 2_097_152 + ($i % 64) * 65_536]);
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
 *
 * @return array{float, int} milliseconds, rows written
 */
function flushMillis(Connection $connection, callable $fill): array
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
    $clock->sleep(11);

    $start = hrtime(true);
    $flushed = $flusher->flush();
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
 * Stores the data set in each of the sixty buckets of the one-hour period, as sixty flushes would.
 *
 * @param array{int, int, int} $dataSet
 *
 * @return int rows stored, every period included
 */
function fillHour(Connection $connection, int $now, array $dataSet): int
{
    [$routes, $queries, $repeated] = $dataSet;
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
            $units[] = new Entry(Entry::TYPE_REQUEST, 'GET route_' . $route, $startedAt, 5.0 + ($minute * 37 + $route) % 1500, [
                'status' => $route % 50 === 0 ? 500 : 200,
                'memory' => 2_097_152 + ($route % 64) * 65_536,
                'sql_n' => 15,
                'sql_ms' => 4.5,
            ], $children);
        }
    }

    $storage = new DbalStorage($connection, 31_536_000);
    $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);
    $connection->executeStatement('DROP TABLE IF EXISTS ' . KeysTable::NAME);
    $storage->setUp();
    $storage->store((new Aggregator(500))->aggregate($units), $now, $labels);

    return (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . AggregatesTable::NAME);
}

/**
 * @return array{float, int} median milliseconds of the builds of the screen on its default sort, rows shown
 */
function displayMillis(Connection $connection, int $now, ScreenInterface $screen): array
{
    $reader = new DbalReader($connection);
    $sorts = $screen->sorts();
    $sort = (string) array_key_first($sorts);

    $timings = [];
    $rows = 0;
    for ($run = 0; $run < DISPLAY_RUNS; ++$run) {
        $start = hrtime(true);
        $view = $screen->build($reader, Period::OneHour, $now, $sort, $sorts[$sort]);
        $timings[] = (hrtime(true) - $start) / 1_000_000;
        $rows = array_sum(array_map(static fn(Table $table): int => \count($table->rows), $view->tables));
    }
    sort($timings);

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

[$aggregateMs, $sampled] = aggregateMillis();
report(sprintf('read and aggregate %d units, no database', LOADED[2]), $aggregateMs, 'ms', 300, sprintf('(%d kept their queries under sampling)', $sampled));

$sqlitePath = tempnam(sys_get_temp_dir(), 'jmonitor_bench_') ?: ':memory:';
$sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $sqlitePath]);
report('flush 10k requests, no SQL (sqlite)', flushMillis($sqlite, 'requestsOnly')[0], 'ms', 50);
$sqlite->close();
@unlink($sqlitePath);

$mysqlUrl = getenv('JMONITOR_TEST_MYSQL_URL');
if (!\is_string($mysqlUrl) || $mysqlUrl === '') {
    echo "JMONITOR_TEST_MYSQL_URL is not set: MySQL flushes skipped\n";

    return;
}

$mysql = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse($mysqlUrl));
report('flush 10k requests, no SQL (mysql)', flushMillis($mysql, 'requestsOnly')[0], 'ms', 50);
foreach (['typical' => [TYPICAL, 100], 'loaded' => [LOADED, 500]] as $name => [$dataSet, $target]) {
    $sampled = 0;
    [$milliseconds, $rows] = flushMillis($mysql, static function (BufferDirectory $directory, ClockInterface $clock) use ($dataSet, &$sampled): void {
        $sampled = fillSlot($directory, $clock, $dataSet);
    });
    report(
        sprintf('flush %s, %d routes, %d queries (mysql)', $name, $dataSet[0], $dataSet[1]),
        $milliseconds,
        'ms',
        $target,
        sprintf('(%d requests, %d kept their queries under sampling, %d rows written)', $dataSet[2], $sampled, $rows),
    );
}

$now = 1_700_003_600;
$stored = fillHour($mysql, $now, DISPLAYED);
foreach ([new RequestsScreen(), new QueriesScreen(true, 100, 10)] as $screen) {
    [$milliseconds, $rows] = displayMillis($mysql, $now, $screen);
    report(sprintf('display, %s, loaded database (mysql)', $screen->id()), $milliseconds, 'ms', 100, sprintf('(%d rows shown, %d rows stored)', $rows, $stored));
}
$mysql->close();
