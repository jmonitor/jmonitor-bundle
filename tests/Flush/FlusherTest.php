<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Flush;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Flush\LabelSync;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\ConnectionProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
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

            public function store(array $aggregates, int $now, array $labels = []): void
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

            public function isUnderPressure(): bool
            {
                return false;
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

        static::assertSame(['4600.ndjson'], array_map('basename', glob($this->dir . '/*/*.ndjson') ?: []));
    }

    public function testSlotLeftAloneForHoursIsStoredOnTheNextFlush(): void
    {
        $this->record(5);
        $this->clock->sleep(7200);

        static::assertTrue($this->flusher($this->dbalStorage())->flushIfDue());

        static::assertSame(5, $this->storedRequests(86400));
        static::assertSame([], glob($this->dir . '/*/*.ndjson'));
    }

    public function testLockIsReleasedWhenTheClockFailsWhileAcquiring(): void
    {
        $broken = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                throw new \RuntimeException('clock stopped');
            }
        };
        $directory = new BufferDirectory($this->dir, 'app');
        $flusher = new Flusher($this->buffer, new Aggregator(500), $this->dbalStorage(), new FlushLock($directory, 10, $broken), $broken);

        static::assertFalse($flusher->flush());
        static::assertInstanceOf(\RuntimeException::class, $flusher->lastFailure());

        static::assertTrue((new FlushLock($directory, 10, $this->clock))->acquire());
    }

    private function refusingStorage(): StorageInterface
    {
        return new class implements StorageInterface {
            public function prepare(): void
            {
                throw new \RuntimeException('no CREATE privilege');
            }

            public function store(array $aggregates, int $now, array $labels = []): void {}

            public function purge(int $now): void {}
        };
    }

    public function testLastFailureIsExposedThenCleared(): void
    {
        $failing = new class implements StorageInterface {
            public bool $fail = true;

            public function prepare(): void {}

            public function store(array $aggregates, int $now, array $labels = []): void
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
        $this->clock->sleep(19);
        static::assertTrue($flusher->flushIfDue());

        $this->record(3);
        $this->clock->sleep(2);

        static::assertFalse($flusher->flushIfDue());
        static::assertTrue($flusher->flush());
        static::assertSame(5, $this->storedRequests());
    }

    public function testStoreReceivesTheTimeOfTheFlush(): void
    {
        $spy = new class implements StorageInterface {
            /** @var list<int> */
            public array $times = [];

            public function prepare(): void {}

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                $this->times[] = $now;
            }

            public function purge(int $now): void {}
        };
        $this->record(2);
        $this->clock->sleep(11);

        static::assertTrue($this->flusher($spy)->flush());

        static::assertSame([1011], $spy->times);
    }

    public function testPurgeRunsAtMostOncePerInterval(): void
    {
        $spy = new class implements StorageInterface {
            public int $purges = 0;

            public function prepare(): void {}

            public function store(array $aggregates, int $now, array $labels = []): void {}

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

    public function testTheRegistryIsWrittenOnlyAfterASuccessfulStore(): void
    {
        $failing = new class implements StorageInterface {
            public function prepare(): void {}

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                throw new \RuntimeException('database down');
            }

            public function purge(int $now): void {}
        };
        $this->record(2);
        $this->clock->sleep(11);

        static::assertFalse($this->flusherWithRegistry($failing)->flush());
        static::assertSame([], glob($this->dir . '/*/hot-keys.json'));

        static::assertTrue($this->flusherWithRegistry($this->dbalStorage())->flush());
        static::assertCount(1, glob($this->dir . '/*/hot-keys.json') ?: []);
    }

    public function testARetryAfterAFailedStoreCountsTheBatchOnce(): void
    {
        $flaky = new class implements StorageInterface {
            public bool $fail = true;

            public function prepare(): void {}

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                if ($this->fail) {
                    $this->fail = false;

                    throw new \RuntimeException('database down');
                }
            }

            public function purge(int $now): void {}
        };
        $flusher = $this->flusherWithRegistry($flaky);
        for ($i = 0; $i < 2; ++$i) {
            $this->buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_home', (float) $this->clock->now()->getTimestamp(), 20.0, [], [
                new Entry(Entry::TYPE_QUERY, 'abc', (float) $this->clock->now()->getTimestamp(), 12.0, ['n' => 1, 'm' => 12.0]),
            ]));
        }
        $this->clock->sleep(11);

        static::assertFalse($flusher->flush());
        static::assertTrue($flusher->flush());

        $files = glob($this->dir . '/*/hot-keys.json') ?: [];
        static::assertCount(1, $files);
        $registry = json_decode((string) file_get_contents($files[0]), true);
        static::assertSame([['abc', 24.0, true]], $registry['families']['query']['keys']);
    }

    public function testTheRecorderIsPausedDuringTheWholeFlush(): void
    {
        $recorder = new Recorder($this->buffer);
        $storage = new class implements StorageInterface {
            public ?Recorder $recorder = null;
            /** @var list<bool> */
            public array $recording = [];

            public function prepare(): void
            {
                $this->recording[] = (bool) $this->recorder?->isRecording();
            }

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                $this->recording[] = (bool) $this->recorder?->isRecording();
            }

            public function purge(int $now): void
            {
                $this->recording[] = (bool) $this->recorder?->isRecording();
            }
        };
        $storage->recorder = $recorder;
        $directory = new BufferDirectory($this->dir, 'app');
        $flusher = new Flusher($this->buffer, new Aggregator(500), $storage, new FlushLock($directory, 10, $this->clock), $this->clock, recorder: $recorder);
        $this->record(1);
        $this->clock->sleep(11);
        $recorder->begin(Entry::TYPE_REQUEST, (float) $this->clock->now()->getTimestamp());

        static::assertTrue($flusher->flush());

        static::assertSame([false, false, false], $storage->recording);
        static::assertTrue($recorder->isRecording());
    }

    public function testTheLabelsOfTheBatchAreStoredThenConfirmed(): void
    {
        $id = '0123456789abcdef';
        $directory = new BufferDirectory($this->dir, 'app');
        $labelStore = new LabelStore($directory);
        $labelStore->write($id, 'SELECT * FROM items WHERE id = ?');
        $storage = $this->labelCapturingStorage();
        $registry = new HotKeys($directory, $this->clock);
        $flusher = new Flusher($this->buffer, new Aggregator(500, $registry), $storage, new FlushLock($directory, 10, $this->clock), $this->clock, null, 300, $registry, null, new LabelSync($labelStore, $registry));
        $this->recordQuery($id);
        $this->clock->sleep(11);

        static::assertTrue($flusher->flush());

        static::assertSame([[$id => 'SELECT * FROM items WHERE id = ?']], $storage->labels);
        static::assertFalse($labelStore->isDue($id, $this->clock->now()->getTimestamp()));
    }

    public function testAFailureAfterTheStoreNeitherFailsNorRepeatsTheFlush(): void
    {
        $broken = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                throw new \RuntimeException('clock stopped');
            }
        };
        $storage = new class implements StorageInterface {
            public int $stores = 0;

            public function prepare(): void {}

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                ++$this->stores;
            }

            public function purge(int $now): void {}
        };
        $logger = $this->memoryLogger();
        $directory = new BufferDirectory($this->dir, 'app');
        $registry = new HotKeys($directory, $this->clock);
        $failingSync = new LabelSync(new LabelStore($directory), new HotKeys(new BufferDirectory($this->dir, 'elsewhere'), $broken));
        $flusher = new Flusher($this->buffer, new Aggregator(500, $registry), $storage, new FlushLock($directory, 10, $this->clock), $this->clock, $logger, 300, $registry, null, $failingSync);

        foreach ([1, 2] as $round) {
            $this->record(1);
            $this->clock->sleep(11);

            static::assertTrue($flusher->flush(), "round {$round}");
            static::assertNull($flusher->lastFailure(), "round {$round}");
            static::assertSame([], glob($this->dir . '/*/*.ndjson'), "round {$round}");
            static::assertSame($round, $storage->stores, "round {$round}");
        }

        static::assertSame(['error JMonitor could not update its bookkeeping after a flush: clock stopped'], $logger->records);
        static::assertCount(1, glob($this->dir . '/jmonitor-app-*/hot-keys.json') ?: []);
    }

    public function testOldLabelsArePrunedEvenWhenNothingIsStored(): void
    {
        $clock = new MockClock('@' . (time() + LabelStore::STORE_EVERY + 60));
        $directory = new BufferDirectory($this->dir, 'app');
        $labelStore = new LabelStore($directory);
        $buffer = new FileBuffer($directory, 10, $clock);
        $failing = new class implements StorageInterface {
            public function prepare(): void {}

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                throw new \RuntimeException('database down');
            }

            public function purge(int $now): void {}
        };
        $flusher = new Flusher($buffer, new Aggregator(500), $failing, new FlushLock($directory, 10, $clock), $clock, labels: new LabelSync($labelStore));

        $labelStore->write('0123456789abcdef', 'SELECT 1');
        static::assertTrue($flusher->flush());
        static::assertFalse($labelStore->exists('0123456789abcdef'));

        $labelStore->write('fedcba9876543210', 'SELECT 2');
        $buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_home', (float) $clock->now()->getTimestamp(), 20.0));
        $clock->sleep(11);
        static::assertFalse($flusher->flush());
        static::assertFalse($labelStore->exists('fedcba9876543210'));
    }

    public function testARepeatedQueryKeepsItsTextWhenItsOwnRowIsFoldedIntoOther(): void
    {
        $directory = new BufferDirectory($this->dir, 'app');
        $labelStore = new LabelStore($directory);
        $registry = new HotKeys($directory, $this->clock);
        $flusher = new Flusher($this->buffer, new Aggregator(500, $registry), $this->dbalStorage(), new FlushLock($directory, 10, $this->clock), $this->clock, null, 300, $registry, null, new LabelSync($labelStore, $registry));
        $now = (float) $this->clock->now()->getTimestamp();

        $heavy = [];
        for ($i = 1; $i <= 250; ++$i) {
            $id = SqlNormalizer::id('SELECT * FROM heavy_' . $i);
            $labelStore->write($id, 'SELECT * FROM heavy_' . $i);
            $heavy[] = new Entry(Entry::TYPE_QUERY, $id, $now, 50.0, ['n' => 1, 'm' => 50.0]);
        }
        $light = SqlNormalizer::id('SELECT * FROM items WHERE id = ?');
        $labelStore->write($light, 'SELECT * FROM items WHERE id = ?');
        $this->buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_heavy', $now, 13000.0, [], $heavy));
        $this->buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_list', $now, 20.0, [], [
            new Entry(Entry::TYPE_QUERY, $light, $now, 3.6, ['n' => 12, 'm' => 0.3]),
            new Entry(Entry::TYPE_NPLUS1, $light, $now, 3.6, ['n' => 12]),
        ]));
        $this->clock->sleep(11);

        static::assertTrue($flusher->flush());

        $reader = new DbalReader($this->connection);
        $queries = $reader->read(new ReportQuery(Period::OneHour, $this->clock->now()->getTimestamp(), Family::Query, Metric::QUERY_DURATION, limit: 300));
        static::assertNotContains($light, array_map(static fn(ReportRow $row): string => $row->key, $queries->rows));
        $repeated = $reader->read(new ReportQuery(Period::OneHour, $this->clock->now()->getTimestamp(), Family::NPlusOne, Metric::NPLUS1_DURATION));
        static::assertCount(1, $repeated->rows);
        static::assertSame([$light => 'SELECT * FROM items WHERE id = ?'], $reader->labels([$light]));
        static::assertFalse($labelStore->isDue($light, $this->clock->now()->getTimestamp()));
    }

    private function labelCapturingStorage(): StorageInterface
    {
        return new class implements StorageInterface {
            /** @var list<array<string, ?string>> */
            public array $labels = [];

            public function prepare(): void {}

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                $this->labels[] = $labels;
            }

            public function purge(int $now): void {}
        };
    }

    private function recordQuery(string $id): void
    {
        $now = (float) $this->clock->now()->getTimestamp();
        $this->buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_home', $now, 20.0, [], [
            new Entry(Entry::TYPE_QUERY, $id, $now, 12.0, ['n' => 1, 'm' => 12.0]),
        ]));
    }

    private function flusherWithRegistry(StorageInterface $storage): Flusher
    {
        $directory = new BufferDirectory($this->dir, 'app');
        $registry = new HotKeys($directory, $this->clock);

        return new Flusher($this->buffer, new Aggregator(500, $registry), $storage, new FlushLock($directory, 10, $this->clock), $this->clock, null, 300, $registry);
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

    private function storedRequests(int $period = 3600): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT SUM(entry_count) FROM jmonitor_aggregates WHERE metric_type = 'request.duration' AND period = ?",
            [$period],
        );
    }
}
