<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Buffer;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Recording\Entry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class FileBufferTest extends TestCase
{
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
        file_put_contents($this->private . '/100.ndjson', "{\"type\":\"requ\n", \FILE_APPEND);
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

    public function testSlotOlderThanMaxAgeIsDeletedUnread(): void
    {
        $clock = new MockClock('@1000');
        $buffer = $this->buffer($clock);
        $buffer->append($this->entry('a'));
        $clock->sleep(3601);

        $called = false;
        $count = $buffer->drain(static function () use (&$called): void {
            $called = true;
        });

        static::assertSame(0, $count);
        static::assertFalse($called);
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
        $size = filesize($this->private . '/100.ndjson');

        static::assertGreaterThan(0, $size);
        static::assertLessThanOrEqual(200 + $line, $size);
        static::assertLessThan(20 * $line, $size);
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
        static::assertSame([$this->privateDir('two') . '/100.ndjson'], glob($this->dir . '/*/*'));
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
        } finally {
            restore_error_handler();
        }

        static::assertSame([], $errors);
    }

    public function testFailedWriteIsSilent(): void
    {
        $clock = new MockClock('@1000');
        mkdir($this->private, 0o700);
        symlink('/dev/full', $this->private . '/100.ndjson');
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
        static::assertSame(0, fileperms($this->private . '/100.ndjson') & 0o077);
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
