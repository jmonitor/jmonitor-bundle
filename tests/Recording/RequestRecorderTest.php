<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestRecorderTest extends TestCase
{
    private string $dir;

    private BufferInterface $buffer;
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-recorder-' . bin2hex(random_bytes(6));
        mkdir($this->dir);

        $this->buffer = new class implements BufferInterface {
            /** @var list<Entry> */
            public array $entries = [];

            public function append(Entry $entry): void
            {
                $this->entries[] = $entry;
            }

            public function drain(callable $consumer, bool $includeCurrent = false): int
            {
                return 0;
            }

            public function isUnderPressure(): bool
            {
                return false;
            }
        };

        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $level . ' ' . $message;
            }
        };
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRecordsOneEntryPerMainRequest(): void
    {
        $this->handle($this->recorder(), Request::create('/'), 'app_home', 200);

        static::assertCount(1, $this->buffer->entries);
        $entry = $this->buffer->entries[0];
        static::assertSame(Entry::TYPE_REQUEST, $entry->type);
        static::assertSame('GET app_home', $entry->key);
        static::assertSame(200, $entry->attributes['status']);
        static::assertGreaterThan(0, $entry->attributes['memory']);
        static::assertGreaterThanOrEqual(0.0, $entry->durationMs);
    }

    public function testDisabledRecorderNeitherRecordsNorFlushes(): void
    {
        $recorder = new RequestRecorder(new Recorder($this->buffer, $this->logger), $this->flusher($this->buffer), [], $this->logger, false);

        $this->handle($recorder, Request::create('/'), 'app_home', 200);

        static::assertSame([], $this->buffer->entries);
        static::assertSame([], glob($this->dir . '/*'));
    }

    public function testUnmatchedRoute(): void
    {
        $this->handle($this->recorder(), Request::create('/nope'), null, 404);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('GET (unmatched)', $this->buffer->entries[0]->key);
        static::assertSame(404, $this->buffer->entries[0]->attributes['status']);
    }

    public function testNonStandardMethodsAreGroupedUnderOther(): void
    {
        $recorder = $this->recorder();

        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'ZZZ9', 'PURGE', 'get'] as $method) {
            $this->handle($recorder, Request::create('/', $method), 'app_home', 200);
        }

        $override = Request::create('/', 'POST');
        $override->headers->set('X-HTTP-Method-Override', 'FOOBAR');
        $this->handle($recorder, $override, 'app_home', 200);

        $invalid = Request::create('/', 'POST');
        $invalid->headers->set('X-HTTP-Method-Override', 'NOT-A-METHOD');
        $this->handle($recorder, $invalid, 'app_home', 200);

        static::assertSame(
            ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'OTHER', 'OTHER', 'GET', 'OTHER', 'OTHER'],
            array_map(static fn(Entry $entry): string => explode(' ', $entry->key)[0], $this->buffer->entries),
        );
        static::assertSame([], $this->logger->records);
    }

    public function testSubRequestsAreIgnored(): void
    {
        $recorder = $this->recorder();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $main = Request::create('/', 'POST');
        $sub = Request::create('/_fragment');

        $recorder->onRequest(new RequestEvent($kernel, $main, HttpKernelInterface::MAIN_REQUEST));
        $main->attributes->set('_route', 'app_home');
        $recorder->onRequest(new RequestEvent($kernel, $sub, HttpKernelInterface::SUB_REQUEST));
        $sub->attributes->set('_route', 'app_fragment');
        $recorder->onTerminate(new TerminateEvent($kernel, $sub, new Response()));
        $recorder->onTerminate(new TerminateEvent($kernel, $main, new Response()));

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('POST app_home', $this->buffer->entries[0]->key);
    }

    public function testInternalAndConfiguredRoutesAreIgnored(): void
    {
        $units = new Recorder($this->buffer, $this->logger);
        $recorder = $this->recorder(['admin_*'], $units);

        foreach (['_wdt', '_profiler_home', 'jmonitor_dashboard', 'admin_users'] as $route) {
            $this->handle($recorder, Request::create('/'), $route, 200);
        }

        static::assertSame([], $this->buffer->entries);
        static::assertFalse($units->isRecording());

        $this->handle($recorder, Request::create('/'), 'app_admin', 200);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('GET app_admin', $this->buffer->entries[0]->key);
    }

    public function testAMarkedRequestIsNotRecorded(): void
    {
        $units = new Recorder($this->buffer, $this->logger);

        $this->handle($this->recorder([], $units), Request::create('/'), 'admin_monitoring', 200, [RequestRecorder::NOT_RECORDED => true]);

        static::assertSame([], $this->buffer->entries);
        static::assertFalse($units->isRecording());
        static::assertSame(0, $units->openUnits());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('provideValuesThatDoNotMark')]
    public function testOnlyTheBooleanTrueMarksARequest(array $attributes): void
    {
        $this->handle($this->recorder(), Request::create('/'), 'app_home', 200, $attributes);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('GET app_home', $this->buffer->entries[0]->key);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideValuesThatDoNotMark(): iterable
    {
        yield 'string 1' => [[RequestRecorder::NOT_RECORDED => '1']];
        yield 'integer 1' => [[RequestRecorder::NOT_RECORDED => 1]];
        yield 'string true' => [[RequestRecorder::NOT_RECORDED => 'true']];
        yield 'false' => [[RequestRecorder::NOT_RECORDED => false]];
        yield 'absent' => [[]];
    }

    public function testAMeasureOfAMarkedRequestIsDroppedWithIt(): void
    {
        $units = new Recorder($this->buffer, $this->logger, null, new QueryDigester(new LabelStore(new BufferDirectory($this->dir, 'app'))));
        $recorder = $this->recorder([], $units);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');

        $recorder->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $request->attributes->set('_route', 'admin_monitoring');
        $units->addSql('SELECT * FROM t WHERE id = 1', 1.0, SqlDialect::Sqlite);
        $request->attributes->set(RequestRecorder::NOT_RECORDED, true);
        $event = new TerminateEvent($kernel, $request, new Response());
        $recorder->onTerminate($event);
        $recorder->onTerminateFlush($event);

        static::assertSame([], $this->buffer->entries);
        static::assertSame(0, $units->openUnits());

        $this->handle($recorder, Request::create('/'), 'app_home', 200);

        static::assertCount(1, $this->buffer->entries);
        static::assertArrayNotHasKey('sql_n', $this->buffer->entries[0]->attributes);
        static::assertSame([], $this->buffer->entries[0]->children);
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('probesFromTheMachineItself')]
    public function testAProbeFromTheMachineItselfOnNoRouteIsNotRecorded(array $server): void
    {
        $this->handle($this->recorder(), self::probe($server), null, 404);

        static::assertSame([], $this->buffer->entries);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function probesFromTheMachineItself(): iterable
    {
        yield 'loopback' => [['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']];
        yield 'another loopback address' => [['REMOTE_ADDR' => '127.0.0.53']];
        yield 'IPv6 loopback' => [['REMOTE_ADDR' => '::1', 'SERVER_ADDR' => '::1']];
        yield 'IPv6 loopback without server address' => [['REMOTE_ADDR' => '::1']];
        yield 'the address of the server' => [['REMOTE_ADDR' => '198.51.100.20', 'SERVER_ADDR' => '198.51.100.20']];
        yield 'a neighbour container' => [['REMOTE_ADDR' => '172.23.0.2', 'SERVER_ADDR' => '172.23.0.3']];
        yield 'a private network' => [['REMOTE_ADDR' => '10.0.0.5']];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('probeHeadersFromElsewhere')]
    public function testAProbeHeaderFromElsewhereIsRecorded(array $server): void
    {
        $request = self::probe($server);
        if (!isset($server['REMOTE_ADDR'])) {
            $request->server->remove('REMOTE_ADDR');
        }

        $this->handle($this->recorder(), $request, null, 404);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('GET (unmatched)', $this->buffer->entries[0]->key);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function probeHeadersFromElsewhere(): iterable
    {
        yield 'a public address' => [['REMOTE_ADDR' => '203.0.113.7', 'SERVER_ADDR' => '198.51.100.20']];
        yield 'a public address claiming to be forwarded for the loopback' => [['REMOTE_ADDR' => '203.0.113.7', 'SERVER_ADDR' => '198.51.100.20', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1']];
        yield 'a 6to4 address' => [['REMOTE_ADDR' => '2002:cb00:7107::1', 'SERVER_ADDR' => '198.51.100.20']];
        yield 'no remote address' => [[]];
        yield 'an empty remote address' => [['REMOTE_ADDR' => '']];
        yield 'a remote address that is not one' => [['REMOTE_ADDR' => 'unknown']];
    }

    public function testAProbeHeaderOnAMatchedRouteIsRecorded(): void
    {
        $this->handle($this->recorder(), self::probe(['REMOTE_ADDR' => '127.0.0.1']), 'app_home', 200);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('GET app_home', $this->buffer->entries[0]->key);
    }

    public function testStaleRequestTimeFallsBackToNow(): void
    {
        $request = Request::create('/', server: ['REQUEST_TIME_FLOAT' => microtime(true) - 7200]);

        $this->handle($this->recorder(), $request, 'app_home', 200);

        static::assertCount(1, $this->buffer->entries);
        static::assertLessThan(1000.0, $this->buffer->entries[0]->durationMs);
    }

    public function testBufferFailureNeverReachesTheApplication(): void
    {
        $failing = new class implements BufferInterface {
            public function append(Entry $entry): void
            {
                throw new \RuntimeException('disk on fire');
            }

            public function drain(callable $consumer, bool $includeCurrent = false): int
            {
                return 0;
            }

            public function isUnderPressure(): bool
            {
                return false;
            }
        };
        $recorder = new RequestRecorder(new Recorder($failing, $this->logger), $this->flusher($failing), [], $this->logger);

        for ($i = 0; $i < 3; ++$i) {
            $this->handle($recorder, Request::create('/'), 'app_home', 200);
        }

        static::assertCount(1, $this->logger->records);
        static::assertStringStartsWith('error ', $this->logger->records[0]);
    }

    public function testFlusherFailureNeverReachesTheApplication(): void
    {
        $directory = new BufferDirectory($this->dir, 'app');
        touch($directory->path() . '/last-flush');
        $broken = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                throw new \RuntimeException('clock stopped');
            }
        };
        $flusher = new Flusher(
            $this->buffer,
            new Aggregator(500),
            $this->storage(),
            new FlushLock($directory, 1, $broken),
            $broken,
        );
        $recorder = new RequestRecorder(new Recorder($this->buffer, $this->logger), $flusher, [], $this->logger);

        for ($i = 0; $i < 3; ++$i) {
            $this->handle($recorder, Request::create('/'), 'app_home', 200);
        }

        static::assertCount(3, $this->buffer->entries);
        static::assertCount(1, $this->logger->records);
        static::assertStringStartsWith('error ', $this->logger->records[0]);
    }

    /**
     * @param list<string> $ignoreRoutes
     */
    private function recorder(array $ignoreRoutes = [], ?Recorder $recorder = null): RequestRecorder
    {
        return new RequestRecorder($recorder ?? new Recorder($this->buffer, $this->logger), $this->flusher($this->buffer), $ignoreRoutes, $this->logger);
    }

    private function flusher(BufferInterface $buffer): Flusher
    {
        $clock = new NativeClock();

        return new Flusher($buffer, new Aggregator(500), $this->storage(), new FlushLock(new BufferDirectory($this->dir, 'app'), 3600, $clock), $clock);
    }

    private function storage(): StorageInterface
    {
        return new class implements StorageInterface {
            public function prepare(): bool
            {
                return false;
            }

            public function store(array $aggregates, int $now, array $labels = []): void {}

            public function purge(int $now): void {}
        };
    }

    /**
     * @param array<string, string> $server
     */
    private static function probe(array $server): Request
    {
        return Request::create('/nginx_status', server: ['HTTP_' . strtoupper(strtr(RequestRecorder::PROBE_HEADER, '-', '_')) => '1'] + $server);
    }

    /**
     * @param array<string, mixed> $attributes set after the request starts, as a host would
     */
    private function handle(RequestRecorder $recorder, Request $request, ?string $route, int $status, array $attributes = []): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        $recorder->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        $request->attributes->add($attributes);

        $event = new TerminateEvent($kernel, $request, new Response('', $status));
        $recorder->onTerminate($event);
        $recorder->onTerminateFlush($event);
    }
}
