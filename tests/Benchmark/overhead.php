<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Dbal\KeysTable;
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
const FLUSH_ENTRIES = 10_000;
const FLUSH_ROUTES = 50;

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

function microsPerCall(callable $operation): float
{
    $start = hrtime(true);
    for ($i = 0; $i < CALLS; ++$i) {
        $operation();
    }

    return (hrtime(true) - $start) / 1000 / CALLS;
}

function flushMillis(Connection $connection): float
{
    $dir = workDir();
    $clock = new MockClock('@1000');
    $directory = new BufferDirectory($dir, 'bench');
    $buffer = new FileBuffer($directory, 10, $clock);
    $lock = new FlushLock($directory, 10, $clock);
    $flusher = new Flusher($buffer, new Aggregator(500), new DbalStorage($connection, 31_536_000), $lock, $clock, null, 300);

    $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);
    $connection->executeStatement('DROP TABLE IF EXISTS ' . KeysTable::NAME);
    $lock->markPurged();
    if (!$flusher->flush()) {
        throw new RuntimeException('Warm-up flush did not run', 0, $flusher->lastFailure());
    }

    for ($i = 0; $i < FLUSH_ENTRIES; ++$i) {
        $buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET route_' . ($i % FLUSH_ROUTES), 1000.0 + $i % 10, 20.0 + $i % 200, ['status' => 200, 'memory' => 2_097_152]));
    }
    $clock->sleep(11);

    $start = hrtime(true);
    $flushed = $flusher->flush();
    $elapsed = (hrtime(true) - $start) / 1_000_000;
    $failure = $flusher->lastFailure();
    removeDir($dir);

    if (!$flushed) {
        throw new RuntimeException('Flush did not run', 0, $failure);
    }

    return $elapsed;
}

$dir = workDir();
$clock = new NativeClock();
$buffer = new FileBuffer(new BufferDirectory($dir, 'bench'), 10, $clock);
$entry = new Entry(Entry::TYPE_REQUEST, 'GET app_hello', microtime(true), 12.5, ['status' => 200, 'memory' => 2_097_152]);
printf("append: %.2f µs/call\n", microsPerCall(static fn() => $buffer->append($entry)));
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
printf("request path: %.2f µs/call\n", $requestPath);
printf("after response: %.2f µs/call\n", $wholeRequest - $requestPath);
$connection->close();
removeDir($dir);

$sqlitePath = tempnam(sys_get_temp_dir(), 'jmonitor_bench_') ?: ':memory:';
$sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $sqlitePath]);
printf("flush 10k (sqlite): %.2f ms\n", flushMillis($sqlite));
$sqlite->close();
@unlink($sqlitePath);

$mysqlUrl = getenv('JMONITOR_TEST_MYSQL_URL');
if (\is_string($mysqlUrl) && $mysqlUrl !== '') {
    $mysql = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse($mysqlUrl));
    printf("flush 10k (mysql): %.2f ms\n", flushMillis($mysql));
}
