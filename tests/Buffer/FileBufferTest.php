<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Buffer;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Recording\Entry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;

final class FileBufferTest extends TestCase
{
    private const RACE_SLOT_SECONDS = 3600;

    private string $dir;
    private string $private;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-buffer-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->private = $this->privateDir('app');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*', \GLOB_ONLYDIR) ?: [] as $dir) {
            is_link($dir) || chmod($dir, 0o700);
        }
        (new Filesystem())->remove($this->dir);
    }

    public function testNothingIsDrainedBeforeTheSlotIsOver(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));
        $clock->sleep(5);

        $called = false;
        $count = $buffer->drain(static function () use (&$called): void {
            $called = true;
        });

        static::assertSame(0, $count);
        static::assertFalse($called);
    }

    public function testCompletedSlotsAreDrainedThenDeleted(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        foreach (['a', 'b', 'c'] as $key) {
            $buffer->append($this->entry($key));
        }
        $clock->sleep(12);
        foreach (['d', 'e'] as $key) {
            $buffer->append($this->entry($key));
        }
        $clock->sleep(9);

        $keys = [];
        $count = $buffer->drain(static function (iterable $entries) use (&$keys): void {
            foreach ($entries as $entry) {
                $keys[] = $entry->key;
            }
        });

        static::assertSame(5, $count);
        static::assertSame(['a', 'b', 'c', 'd', 'e'], $keys);
        static::assertSame([], glob($this->private . '/*'));
    }

    public function testSlotIsKeptWhenTheConsumerThrows(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));
        $buffer->append($this->entry('b'));
        $clock->sleep(11);

        try {
            $buffer->drain(static function (iterable $entries): void {
                foreach ($entries as $entry) {
                }
                throw new \RuntimeException('boom');
            });
            static::fail('The exception should propagate.');
        } catch (\RuntimeException $e) {
            static::assertSame('boom', $e->getMessage());
        }

        static::assertCount(1, glob($this->private . '/*') ?: []);

        $keys = [];
        $buffer->drain(static function (iterable $entries) use (&$keys): void {
            foreach ($entries as $entry) {
                $keys[] = $entry->key;
            }
        });
        static::assertSame(['a', 'b'], $keys);
    }

    public function testCorruptedLinesAreSkipped(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));
        file_put_contents($this->private . '/1000.ndjson', "{\"type\":\"requ\n", \FILE_APPEND);
        $buffer->append($this->entry('b'));
        $clock->sleep(11);

        $keys = [];
        $count = $buffer->drain(static function (iterable $entries) use (&$keys): void {
            foreach ($entries as $entry) {
                $keys[] = $entry->key;
            }
        });

        static::assertSame(2, $count);
        static::assertSame(['a', 'b'], $keys);
    }

    public function testSlotOlderThanMaxAgeIsDrainedWhenTheConsumerSucceeds(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));
        $clock->sleep(7200);

        $keys = [];
        $count = $buffer->drain(static function (iterable $entries) use (&$keys): void {
            foreach ($entries as $entry) {
                $keys[] = $entry->key;
            }
        });

        static::assertSame(1, $count);
        static::assertSame(['a'], $keys);
        static::assertSame([], glob($this->private . '/*'));
    }

    public function testSlotsOlderThanMaxAgeAreDeletedWhenTheConsumerThrows(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        for ($i = 0; $i < 70; ++$i) {
            $buffer->append($this->entry('old'));
            $clock->sleep(10);
        }
        $clock->sleep(7200);
        $buffer->append($this->entry('recent'));
        $clock->sleep(11);

        try {
            $buffer->drain(static function (iterable $entries): void {
                throw new \RuntimeException('boom');
            });
            static::fail('The exception should propagate.');
        } catch (\RuntimeException $e) {
            static::assertSame('boom', $e->getMessage());
        }

        static::assertSame(['8900.ndjson'], array_map('basename', glob($this->private . '/*') ?: []));
    }

    public function testHourLongSlotSurvivesAFailureRightAfterItEnds(): void
    {
        $clock = new MockClock('@3600');
        $buffer = new FileBuffer(new BufferDirectory($this->dir, 'app'), 3600, $clock);
        $buffer->append($this->entry('a'));
        $clock->sleep(3601);

        $this->drainWithFailure($buffer);

        $keys = [];
        $count = $buffer->drain(static function (iterable $entries) use (&$keys): void {
            foreach ($entries as $entry) {
                $keys[] = $entry->key;
            }
        });

        static::assertSame(1, $count);
        static::assertSame(['a'], $keys);
    }

    public function testHourLongSlotEndedMoreThanMaxAgeAgoIsDeletedWhenTheConsumerThrows(): void
    {
        $clock = new MockClock('@3600');
        $buffer = new FileBuffer(new BufferDirectory($this->dir, 'app'), 3600, $clock);
        $buffer->append($this->entry('a'));
        $clock->sleep(3600 + 3601);

        $this->drainWithFailure($buffer);

        static::assertSame([], glob($this->private . '/*'));
    }

    public function testBacklogIsDrainedOldestFirstOverSeveralCalls(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        for ($i = 0; $i < 70; ++$i) {
            $buffer->append($this->entry('k' . $i));
            $clock->sleep(10);
        }
        $clock->sleep(1);

        $keys = [];
        $consumer = static function (iterable $entries) use (&$keys): void {
            foreach ($entries as $entry) {
                $keys[] = $entry->key;
            }
        };

        static::assertSame(60, $buffer->drain($consumer));
        static::assertCount(10, glob($this->private . '/*') ?: []);
        static::assertSame(10, $buffer->drain($consumer));
        static::assertSame(0, $buffer->drain($consumer));
        static::assertSame(array_map(static fn(int $i): string => 'k' . $i, range(0, 69)), $keys);
    }

    public function testSlotIsDrainedOnlyOnceTheGraceDelayIsOver(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));
        $clock->sleep(10);

        $called = false;
        static::assertSame(0, $buffer->drain(static function () use (&$called): void {
            $called = true;
        }));
        static::assertFalse($called);

        $clock->sleep(1);

        static::assertSame(1, $buffer->drain(static function (iterable $entries): void {
            foreach ($entries as $entry) {
            }
        }));
    }

    /**
     * @return iterable<string, array{int, int, list<int>}>
     */
    public static function intervalChanges(): iterable
    {
        yield 'from 10 to 60 seconds' => [10, 60, [0, 15, 30]];
        yield 'from 60 to 10 seconds' => [60, 10, [0, 70]];
    }

    /**
     * @param list<int> $offsets
     */
    #[DataProvider('intervalChanges')]
    public function testSlotsWrittenWithAnotherIntervalAreDrained(int $written, int $read, array $offsets): void
    {
        $clock = new MockClock('@1700000000');
        $writer = new FileBuffer(new BufferDirectory($this->dir, 'app'), $written, $clock);
        $elapsed = 0;
        foreach ($offsets as $i => $offset) {
            $clock->sleep($offset - $elapsed);
            $elapsed = $offset;
            $writer->append($this->entry('k' . $i));
        }
        $clock->sleep(100 - $elapsed);

        $keys = [];
        $reader = new FileBuffer(new BufferDirectory($this->dir, 'app'), $read, $clock);
        $count = $reader->drain(static function (iterable $entries) use (&$keys): void {
            foreach ($entries as $entry) {
                $keys[] = $entry->key;
            }
        });

        static::assertSame(\count($offsets), $count);
        static::assertSame(array_map(static fn(int $i): string => 'k' . $i, array_keys($offsets)), $keys);
        static::assertSame([], glob($this->private . '/*'));
    }

    public function testAppendStopsAtMaxSlotBytes(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock, 'app', 200);
        for ($i = 0; $i < 20; ++$i) {
            $buffer->append($this->entry('GET route_' . $i));
        }

        $line = \strlen($this->entry('GET route_10')->toLine()) + 1;
        clearstatcache();
        $size = filesize($this->private . '/1000.ndjson');

        static::assertGreaterThan(0, $size);
        static::assertLessThanOrEqual(200 + $line, $size);
        static::assertLessThan(20 * $line, $size);
    }

    public function testPressureStartsAtAQuarterOfTheSlotSize(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock, 'app', 4000);
        $overhead = \strlen($this->entry('')->toLine()) + 1;

        static::assertFalse($buffer->isUnderPressure());

        $buffer->append($this->entry(str_repeat('a', 900 - $overhead)));
        clearstatcache();

        static::assertSame(900, filesize($this->private . '/1000.ndjson'));
        static::assertFalse($buffer->isUnderPressure());

        $buffer->append($this->entry(str_repeat('b', 200 - $overhead)));
        clearstatcache();

        static::assertSame(1100, filesize($this->private . '/1000.ndjson'));
        static::assertTrue($buffer->isUnderPressure());

        $clock->sleep(10);

        static::assertFalse($buffer->isUnderPressure());
    }

    public function testOtherApplicationsAreIgnored(): void
    {
        $clock = new MockClock('@1000');
        $one = $this->buffer($clock, 'one');
        $two = $this->buffer($clock, 'two');
        $one->append($this->entry('from-one'));
        $two->append($this->entry('from-two'));
        $clock->sleep(11);

        $keys = [];
        $one->drain(static function (iterable $entries) use (&$keys): void {
            foreach ($entries as $entry) {
                $keys[] = $entry->key;
            }
        });

        static::assertSame(['from-one'], $keys);
        static::assertSame([$this->privateDir('two') . '/1000.ndjson'], glob($this->dir . '/*/*'));
    }

    public function testUnwritableDirectoryIsSilent(): void
    {
        $buffer = new FileBuffer(new BufferDirectory('/proc/jmonitor-nope', 'app'), 10, new MockClock('@1000'));
        $errors = [];
        set_error_handler(static function (int $no, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });

        try {
            $buffer->append($this->entry('a'));
            $buffer->drain(static function (): void {});
            $pressure = $buffer->isUnderPressure();
        } finally {
            restore_error_handler();
        }

        static::assertSame([], $errors);
        static::assertNull($pressure);
    }

    public function testFailedWriteIsSilent(): void
    {
        $clock = new MockClock('@1000');
        mkdir($this->private, 0o700);
        symlink('/dev/full', $this->private . '/1000.ndjson');
        $errors = [];
        set_error_handler(static function (int $no, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });

        try {
            $this->buffer($clock)->append($this->entry('a'));
        } finally {
            restore_error_handler();
        }

        static::assertSame([], $errors);
    }

    public function testDirectoryIsPrivateToTheUserAndItsFilesAreNotReadableByOthers(): void
    {
        $this->buffer(new MockClock('@1000'))->append($this->entry('a'));

        static::assertSame(0o700, fileperms($this->private) & 0o777);
        static::assertSame(0, fileperms($this->private . '/1000.ndjson') & 0o077);
    }

    public function testWithoutBaseDirectoryTheSystemTemporaryDirectoryIsUsed(): void
    {
        $appId = 'runtime-' . bin2hex(random_bytes(4));
        $path = (new BufferDirectory(null, $appId))->path();

        try {
            static::assertSame(sys_get_temp_dir() . '/jmonitor-' . $appId . '-u' . posix_geteuid(), $path);
        } finally {
            (new Filesystem())->remove((string) $path);
        }
    }

    public function testDirectoryOpenToOtherUsersIsRefused(): void
    {
        mkdir($this->private, 0o700);
        chmod($this->private, 0o777);

        $this->assertRefused();
    }

    public function testSymlinkInPlaceOfTheDirectoryIsRefused(): void
    {
        $target = $this->dir . '/elsewhere';
        mkdir($target, 0o700);
        symlink($target, $this->private);

        $this->assertRefused();
    }

    public function testSlotThatCannotBeDeletedIsNotIngestedTwice(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));
        $buffer->append($this->entry('b'));
        $clock->sleep(11);

        chmod($this->private, 0o500);
        try {
            $first = $buffer->drain(static function (iterable $entries): void {
                foreach ($entries as $entry) {
                }
            });
        } finally {
            chmod($this->private, 0o700);
        }

        $second = $buffer->drain(static function (iterable $entries): void {
            foreach ($entries as $entry) {
            }
        });

        static::assertSame(2, $first);
        static::assertSame(0, $second);
    }

    public function testSymlinkSwappedInAfterValidationIsRefused(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));
        $target = $this->dir . '/open';
        mkdir($target);
        chmod($target, 0o777);

        rename($this->private, $this->dir . '/moved');
        symlink($target, $this->private);
        $buffer->append($this->entry('b'));

        static::assertSame([], glob($target . '/*'));
    }

    public function testWithoutPosixTheOwnerIsNotComparedWithTheScriptOwner(): void
    {
        if (posix_geteuid() === 0 || fileowner('/etc/passwd') !== 0) {
            static::markTestSkipped('Needs a non-root process and a root-owned /etc/passwd.');
        }

        $command = [
            \PHP_BINARY,
            '-d', 'disable_functions=posix_geteuid',
            '-d', 'auto_prepend_file=' . \dirname(__DIR__) . '/Fixtures/Buffer/resolve_directory.php',
            '/etc/passwd',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['JMONITOR_BUFFER_BASE' => $this->dir] + getenv());
        static::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);

        static::assertSame('', $errors);
        static::assertSame($this->dir . '/jmonitor-app-u0', $output);
        static::assertSame(0o700, fileperms($output) & 0o777);
    }

    public function testTheSlotBeingWrittenIsDrainedWhenAsked(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));

        static::assertSame(0, $buffer->drain($this->consumer()));
        static::assertSame(1, $buffer->drain($this->consumer(), true));
        static::assertSame([], glob($this->private . '/*.ndjson'));
    }

    public function testATakenSlotLeftBehindIsDrainedOnceByTheNextDrain(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        mkdir($this->private, 0o700);
        file_put_contents($this->private . '/100-deadbeef.ndjson', $this->entry('a')->toLine() . "\n" . $this->entry('b')->toLine() . "\n");

        $keys = [];
        static::assertSame(2, $buffer->drain($this->consumer($keys)));
        static::assertSame(['a', 'b'], $keys);
        static::assertSame(0, $buffer->drain($this->consumer()));
    }

    public function testATakenSlotIsDrainedEvenWhenItsStartIsStillRunning(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('current'));
        file_put_contents($this->private . '/1000-deadbeef.ndjson', $this->entry('taken')->toLine() . "\n");

        $keys = [];
        static::assertSame(1, $buffer->drain($this->consumer($keys)));
        static::assertSame(['taken'], $keys);
        static::assertSame(['1000.ndjson'], array_map('basename', glob($this->private . '/*') ?: []));
    }

    public function testTakenSlotsCountTowardsTheSlotsDrainedAtOnce(): void
    {
        $clock = new MockClock('@1000');
        $buffer = new FileBuffer(new BufferDirectory($this->dir, 'app'), 10, $clock, maxSlotsPerDrain: 2);
        mkdir($this->private, 0o700);
        foreach ([100, 200, 300] as $start) {
            file_put_contents(sprintf('%s/%d-deadbeef.ndjson', $this->private, $start), $this->entry('k' . $start)->toLine() . "\n");
        }

        $keys = [];
        static::assertSame(2, $buffer->drain($this->consumer($keys)));
        static::assertSame(['k100', 'k200'], $keys);
        static::assertSame(1, $buffer->drain($this->consumer($keys)));
    }

    public function testATakenSlotIsKeptWhenTheConsumerThrows(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));

        try {
            $buffer->drain(static function (iterable $entries): void {
                throw new \RuntimeException('boom');
            }, true);
            static::fail('The exception should propagate.');
        } catch (\RuntimeException $e) {
            static::assertSame('boom', $e->getMessage());
        }

        static::assertCount(1, glob($this->private . '/1000-*.ndjson') ?: []);

        $keys = [];
        static::assertSame(1, $buffer->drain($this->consumer($keys)));
        static::assertSame(['a'], $keys);
        static::assertSame(0, $buffer->drain($this->consumer(), true));
    }

    public function testATakenSlotOlderThanMaxAgeIsDeletedWhenTheConsumerThrows(): void
    {
        $clock = new MockClock('@10000');
        $buffer = $this->buffer($clock);
        mkdir($this->private, 0o700);
        file_put_contents($this->private . '/100-deadbeef.ndjson', $this->entry('old')->toLine() . "\n");

        $this->drainWithFailure($buffer);

        static::assertSame([], glob($this->private . '/*'));
    }

    public function testATakenSlotThatCannotBeDeletedIsNotIngestedTwice(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('current'));
        file_put_contents($this->private . '/100-deadbeef.ndjson', $this->entry('a')->toLine() . "\n");

        $keys = [];
        chmod($this->private, 0o500);
        try {
            $first = $buffer->drain($this->consumer($keys), true);
        } finally {
            chmod($this->private, 0o700);
        }

        static::assertSame(1, $first);
        static::assertSame(0, $buffer->drain($this->consumer()));
        static::assertSame(1, $buffer->drain($this->consumer($keys), true));
        static::assertSame(['a', 'current'], $keys);
    }

    public function testAppendsGoOnInAFreshFileOnceTheSlotWasTaken(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));

        $keys = [];
        static::assertSame(1, $buffer->drain($this->consumer($keys), true));

        $buffer->append($this->entry('b'));

        static::assertSame(1, $buffer->drain($this->consumer($keys), true));
        static::assertSame(['a', 'b'], $keys);
    }

    public function testNoLineIsLostNorCountedTwiceWhileWritersAndClosingDrainsRace(): void
    {
        $writers = 4;
        $lines = 2000;
        $buffer = new FileBuffer(new BufferDirectory($this->dir, 'app'), self::RACE_SLOT_SECONDS, new NativeClock());
        $seen = [];
        $consumer = static function (iterable $entries) use (&$seen): void {
            foreach ($entries as $entry) {
                $seen[$entry->key] = ($seen[$entry->key] ?? 0) + 1;
            }
        };
        $exposed = [];

        $umask = umask(0o022);
        try {
            $processes = [];
            for ($n = 0; $n < $writers; ++$n) {
                $process = proc_open(
                    [\PHP_BINARY, \dirname(__DIR__) . '/Fixtures/Buffer/append_lines.php', $this->dir, (string) self::RACE_SLOT_SECONDS, 'w' . $n, (string) $lines],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                static::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }

            $running = $processes;
            while ($running !== []) {
                $buffer->drain($consumer, true);
                $exposed = array_merge($exposed, $this->slotsWithLinesOpenToOthers());
                $running = array_filter($running, static fn(array $process): bool => proc_get_status($process[0])['running']);
            }
            $buffer->drain($consumer, true);
            $exposed = array_merge($exposed, $this->slotsWithLinesOpenToOthers());
        } finally {
            umask($umask);
        }

        foreach ($processes as [$process, $pipes]) {
            static::assertSame('', stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
            proc_close($process);
        }

        $expected = [];
        for ($n = 0; $n < $writers; ++$n) {
            for ($i = 0; $i < $lines; ++$i) {
                $expected[] = sprintf('w%d-%d', $n, $i);
            }
        }

        $lost = array_values(array_diff($expected, array_keys($seen)));
        $twice = array_keys(array_filter($seen, static fn(int $times): bool => $times > 1));

        static::assertSame(0, \count($lost), 'Lost lines, starting with ' . implode(', ', \array_slice($lost, 0, 10)));
        static::assertSame(0, \count($twice), 'Lines read twice, starting with ' . implode(', ', \array_slice($twice, 0, 10)));
        static::assertCount($writers * $lines, $seen);
        static::assertSame([], $exposed, 'Slots holding lines were readable by group or others');
    }

    public function testTheSlotBeingWrittenIsMadePrivateWhenFoundOpenToOthers(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        mkdir($this->private, 0o700);
        touch($this->private . '/1000.ndjson');
        chmod($this->private . '/1000.ndjson', 0o644);

        $buffer->append($this->entry('a'));

        clearstatcache();
        static::assertSame(0o600, fileperms($this->private . '/1000.ndjson') & 0o777);
    }

    /**
     * @return list<string>
     */
    private function slotsWithLinesOpenToOthers(): array
    {
        $exposed = [];
        clearstatcache();
        foreach (glob($this->private . '/*.ndjson') ?: [] as $file) {
            $stat = BufferDirectory::quietly(static fn(): array|false => stat($file));
            if ($stat !== false && $stat['size'] > 0 && ($stat['mode'] & 0o077) !== 0) {
                $exposed[] = basename($file) . sprintf(' %o', $stat['mode'] & 0o777);
            }
        }

        return $exposed;
    }

    /**
     * @param list<string> $keys
     */
    private function consumer(array &$keys = []): \Closure
    {
        return static function (iterable $entries) use (&$keys): void {
            foreach ($entries as $entry) {
                $keys[] = $entry->key;
            }
        };
    }

    private function drainWithFailure(FileBuffer $buffer): void
    {
        try {
            $buffer->drain(static function (iterable $entries): void {
                throw new \RuntimeException('deadlock');
            });
            static::fail('The exception should propagate.');
        } catch (\RuntimeException $e) {
            static::assertSame('deadlock', $e->getMessage());
        }
    }

    private function assertRefused(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $errors = [];
        set_error_handler(static function (int $no, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });

        try {
            $buffer->append($this->entry('a'));
            $written = glob($this->private . '/*');
            $clock->sleep(11);
            $count = $buffer->drain(static function (iterable $entries): void {
                foreach ($entries as $entry) {
                }
            });
        } finally {
            restore_error_handler();
        }

        static::assertSame([], $errors);
        static::assertSame([], $written);
        static::assertSame(0, $count);
    }

    private function buffer(MockClock $clock, string $appId = 'app', int $maxSlotBytes = 16_777_216): FileBuffer
    {
        return new FileBuffer(new BufferDirectory($this->dir, $appId), 10, $clock, $maxSlotBytes);
    }

    private function privateDir(string $appId): string
    {
        return $this->dir . '/jmonitor-' . $appId . '-u' . posix_geteuid();
    }

    private function entry(string $key): Entry
    {
        return new Entry(Entry::TYPE_REQUEST, $key, 1000.0, 1.0);
    }
}
