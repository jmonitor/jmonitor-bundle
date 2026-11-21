<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Flush;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class FlushLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-lock-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testDueOnFirstCallThenNotUntilTheIntervalElapses(): void
    {
        $clock = new MockClock('@1000');
        $lock = $this->lock($clock);

        static::assertTrue($lock->isDue());

        static::assertTrue($lock->acquire());
        $lock->release();

        static::assertFalse($lock->isDue());
        $clock->sleep(9);
        static::assertFalse($lock->isDue());
        $clock->sleep(1);
        static::assertTrue($lock->isDue());
    }

    public function testDueAgainstAnExplicitInterval(): void
    {
        $clock = new MockClock('@1000');
        $lock = $this->lock($clock);

        static::assertTrue($lock->isDue(1));

        static::assertTrue($lock->acquire());
        $lock->release();

        static::assertFalse($lock->isDue(1));
        $clock->sleep(1);
        static::assertTrue($lock->isDue(1));
        static::assertFalse($lock->isDue());
        $clock->sleep(9);
        static::assertTrue($lock->isDue());
    }

    public function testOnlyOneHolder(): void
    {
        $clock = new MockClock('@1000');
        $first = $this->lock($clock);
        $second = $this->lock($clock);

        static::assertTrue($first->acquire());
        static::assertFalse($second->acquire());

        $first->release();

        static::assertTrue($second->acquire());
    }

    public function testUnwritableDirectoryNeverAcquires(): void
    {
        $lock = new FlushLock(new BufferDirectory('/proc', 'app'), 10, new MockClock('@1000'));

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $due = $lock->isDue();
            $acquired = $lock->acquire();
            $lock->release();
        } finally {
            restore_error_handler();
        }

        static::assertFalse($due);
        static::assertFalse($acquired);
        static::assertSame([], $warnings);
    }

    public function testMarkerDatedInTheFutureIsDue(): void
    {
        $clock = new MockClock('@1000');
        $lock = $this->lock($clock);
        static::assertTrue($lock->acquire());
        $lock->release();
        $lock->markPurged();

        $marker = $this->dir . '/jmonitor-app-u' . posix_geteuid() . '/last-flush';
        touch($marker, 5000);
        touch(\dirname($marker) . '/last-purge', 5000);

        static::assertTrue($lock->isDue());
        static::assertTrue($lock->isPurgeDue(300));
        static::assertTrue($lock->acquire());
        $lock->release();
        clearstatcache(true, $marker);
        static::assertSame(1000, filemtime($marker));
        static::assertFalse($lock->isDue());
    }

    public function testRefusedDirectoryIsNeverDue(): void
    {
        $private = $this->dir . '/jmonitor-app-u' . posix_geteuid();
        mkdir($private, 0o700);
        chmod($private, 0o777);
        $lock = $this->lock(new MockClock('@1000'));

        static::assertFalse($lock->isDue());
        static::assertFalse($lock->acquire());
        static::assertSame([], glob($private . '/*'));
    }

    private function lock(MockClock $clock): FlushLock
    {
        return new FlushLock(new BufferDirectory($this->dir, 'app'), 10, $clock);
    }
}
