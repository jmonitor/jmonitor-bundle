<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Flush;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\ConnectionProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class FlusherTest extends TestCase
{
    private string $dir;
    private MockClock $clock;
    private Connection $connection;
    private FileBuffer $buffer;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-flush-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->clock = new MockClock('@1000');
        $this->buffer = new FileBuffer(new BufferDirectory($this->dir, 'app'), 10, $this->clock);
        [$this->connection] = ConnectionProvider::all()['sqlite'];
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testFlushMovesCompletedSlotsToStorage(): void
    {
        $flusher = $this->flusher($this->dbalStorage());
        $this->record(5);
        $this->clock->sleep(11);

        static::assertTrue($flusher->flushIfDue());

        static::assertSame(5, $this->storedRequests());
        static::assertSame([], glob($this->dir . '/*/*.ndjson'));
    }

    public function testNotDueDoesNothing(): void
    {
        $flusher = $this->flusher($this->dbalStorage());

        static::assertTrue($flusher->flushIfDue());
        $this->clock->sleep(3);
        static::assertFalse($flusher->flushIfDue());
    }

    public function testStorageFailureKeepsTheSlots(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'error') {
                    $this->errors[] = (string) $message;
                }
            }
        };
        $failing = new class implements StorageInterface {
            public function prepare(): void {}

            public function store(array $aggregates): void
            {
                throw new \RuntimeException('database down');
            }

            public function purge(int $now): void {}
        };
        $this->record(5);
        $this->clock->sleep(11);

        static::assertFalse($this->flusher($failing, $logger)->flushIfDue());

        static::assertCount(1, glob($this->dir . '/*/*.ndjson'));
        static::assertCount(1, $logger->errors);

        $this->clock->sleep(11);
        static::assertTrue($this->flusher($this->dbalStorage())->flushIfDue());
        static::assertSame(5, $this->storedRequests());
    }

    public function testBufferIsNotReadWhenTheStorageCannotWrite(): void
    {
        $logger = $this->memoryLogger();
        $buffer = new class implements BufferInterface {
            public int $consumed = 0;

            public function append(Entry $entry): void {}

            public function drain(callable $consumer): int
            {
                $consumer((function (): \Generator {
                    ++$this->consumed;

                    yield new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1000.0, 1.0);
                })());

                return $this->consumed;
            }
        };
        $flusher = new Flusher($buffer, new Aggregator(500), $this->refusingStorage(), new FlushLock(new BufferDirectory($this->dir, 'app'), 10, $this->clock), $this->clock, $logger, 300);

        static::assertFalse($flusher->flush());

        static::assertSame(0, $buffer->consumed);
        static::assertInstanceOf(\RuntimeException::class, $flusher->lastFailure());
        static::assertCount(1, $logger->records);
    }

    public function testExpiredSlotIsDeletedEvenWhenTheStorageRefuses(): void
    {
        $this->record(5);
        $this->clock->sleep(3601);
        $this->record(2);
        $this->clock->sleep(11);

        static::assertFalse($this->flusher($this->refusingStorage())->flush());

        static::assertSame(['460.ndjson'], array_map('basename', glob($this->dir . '/*/*.ndjson') ?: []));
    }

    private function refusingStorage(): StorageInterface
    {
        return new class implements StorageInterface {
            public function prepare(): void
            {
                throw new \RuntimeException('no CREATE privilege');
            }

            public function store(array $aggregates): void {}

            public function purge(int $now): void {}
        };
    }

    public function testLastFailureIsExposedThenCleared(): void
    {
        $failing = new class implements StorageInterface {
            public bool $fail = true;

            public function prepare(): void {}

            public function store(array $aggregates): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('database down');
                }
            }

            public function purge(int $now): void {}
        };
        $flusher = $this->flusher($failing);
        $this->record(2);
        $this->clock->sleep(11);

        static::assertFalse($flusher->flushIfDue());
        static::assertInstanceOf(\RuntimeException::class, $flusher->lastFailure());

        $failing->fail = false;
        $this->clock->sleep(11);

        static::assertTrue($flusher->flushIfDue());
        static::assertNull($flusher->lastFailure());
    }

    public function testFlushIgnoresTheInterval(): void
    {
        $flusher = $this->flusher($this->dbalStorage());
        $this->record(2);
        $this->clock->sleep(11);
        static::assertTrue($flusher->flushIfDue());

        $this->record(3);
        $this->clock->sleep(9);

        static::assertFalse($flusher->flushIfDue());
        static::assertTrue($flusher->flush());
        static::assertSame(5, $this->storedRequests());
    }

    public function testPurgeRunsAtMostOncePerInterval(): void
    {
        $spy = new class implements StorageInterface {
            public int $purges = 0;

            public function prepare(): void {}

            public function store(array $aggregates): void {}

            public function purge(int $now): void
            {
                ++$this->purges;
            }
        };
        $flusher = $this->flusher($spy);

        for ($i = 0; $i < 3; ++$i) {
            static::assertTrue($flusher->flush());
            $this->clock->sleep(10);
        }
        static::assertSame(1, $spy->purges);

        $this->clock->sleep(300);
        static::assertTrue($flusher->flush());
        static::assertSame(2, $spy->purges);
    }

    public function testOpenTransactionOnTheConnectionKeepsTheSlots(): void
    {
        $logger = $this->memoryLogger();
        $flusher = $this->flusher($this->dbalStorage(), $logger);
        $this->record(5);
        $this->clock->sleep(11);

        $this->connection->beginTransaction();
        try {
            static::assertFalse($flusher->flush());
        } finally {
            $this->connection->rollBack();
        }

        static::assertNotNull($flusher->lastFailure());
        static::assertCount(1, $logger->records);
        static::assertCount(1, glob($this->dir . '/*/*.ndjson') ?: []);

        static::assertTrue($flusher->flush());
        static::assertSame(5, $this->storedRequests());
    }

    public function testUnreachableDatabaseKeepsTheSlots(): void
    {
        $unreachable = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dir . '/missing/db.sqlite']);
        $logger = $this->memoryLogger();
        $flusher = $this->flusher(new DbalStorage($unreachable, 31_536_000), $logger);
        $this->record(5);
        $this->clock->sleep(11);

        static::assertFalse($flusher->flush());

        static::assertNotNull($flusher->lastFailure());
        static::assertCount(1, $logger->records);
        static::assertCount(1, glob($this->dir . '/*/*.ndjson') ?: []);
    }

    private function memoryLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $level . ' ' . $message;
            }
        };
    }

    private function flusher(StorageInterface $storage, ?AbstractLogger $logger = null): Flusher
    {
        return new Flusher(
            $this->buffer,
            new Aggregator(500),
            $storage,
            new FlushLock(new BufferDirectory($this->dir, 'app'), 10, $this->clock),
            $this->clock,
            $logger,
            300,
        );
    }

    private function dbalStorage(): DbalStorage
    {
        return new DbalStorage($this->connection, 31_536_000);
    }

    private function record(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_home', (float) $this->clock->now()->getTimestamp(), 20.0, ['status' => 200]));
        }
    }

    private function storedRequests(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT SUM(entry_count) FROM jmonitor_aggregates WHERE metric_type = 'request.duration' AND period = 3600",
        );
    }
}
