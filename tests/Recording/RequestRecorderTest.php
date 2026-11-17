<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\NativeClock;
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

            public function drain(callable $consumer): int
            {
                return 0;
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
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
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

    public function testUnmatchedRoute(): void
    {
        $this->handle($this->recorder(), Request::create('/nope'), null, 404);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('GET (unmatched)', $this->buffer->entries[0]->key);
        static::assertSame(404, $this->buffer->entries[0]->attributes['status']);
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
        $recorder = $this->recorder(['admin_*']);

        foreach (['_wdt', '_profiler_home', 'jmonitor_dashboard', 'admin_users'] as $route) {
            $this->handle($recorder, Request::create('/'), $route, 200);
        }

        static::assertSame([], $this->buffer->entries);

        $this->handle($recorder, Request::create('/'), 'app_admin', 200);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('GET app_admin', $this->buffer->entries[0]->key);
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

            public function drain(callable $consumer): int
            {
                return 0;
            }
        };
        $recorder = new RequestRecorder($failing, $this->flusher($failing), [], $this->logger);

        for ($i = 0; $i < 3; ++$i) {
            $this->handle($recorder, Request::create('/'), 'app_home', 200);
        }

        static::assertCount(1, $this->logger->records);
        static::assertStringStartsWith('error ', $this->logger->records[0]);
    }

    public function testFlusherFailureNeverReachesTheApplication(): void
    {
        touch($this->dir . '/jmonitor-app.flush');
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
            new FlushLock($this->dir, 'app', 1, $broken),
            $broken,
        );
        $recorder = new RequestRecorder($this->buffer, $flusher, [], $this->logger);

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
    private function recorder(array $ignoreRoutes = []): RequestRecorder
    {
        return new RequestRecorder($this->buffer, $this->flusher($this->buffer), $ignoreRoutes, $this->logger);
    }

    private function flusher(BufferInterface $buffer): Flusher
    {
        $clock = new NativeClock();

        return new Flusher($buffer, new Aggregator(500), $this->storage(), new FlushLock($this->dir, 'app', 3600, $clock), $clock);
    }

    private function storage(): StorageInterface
    {
        return new class implements StorageInterface {
            public function store(array $aggregates): void {}

            public function purge(int $now): void {}
        };
    }

    private function handle(RequestRecorder $recorder, Request $request, ?string $route, int $status): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        $recorder->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        $event = new TerminateEvent($kernel, $request, new Response('', $status));
        $recorder->onTerminate($event);
        $recorder->onTerminateFlush($event);
    }
}
