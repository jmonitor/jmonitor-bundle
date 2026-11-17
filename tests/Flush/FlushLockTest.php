<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Flush;

use Jmonitor\JmonitorBundle\Flush\FlushLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

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
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testDueOnFirstCallThenNotUntilTheIntervalElapses(): void
    {
        $clock = new MockClock('@1000');
        $lock = new FlushLock($this->dir, 'app', 10, $clock);

        static::assertTrue($lock->isDue());

        static::assertTrue($lock->acquire());
        $lock->release();

        static::assertFalse($lock->isDue());
        $clock->sleep(9);
        static::assertFalse($lock->isDue());
        $clock->sleep(1);
        static::assertTrue($lock->isDue());
    }

    public function testOnlyOneHolder(): void
    {
        $clock = new MockClock('@1000');
        $first = new FlushLock($this->dir, 'app', 10, $clock);
        $second = new FlushLock($this->dir, 'app', 10, $clock);

        static::assertTrue($first->acquire());
        static::assertFalse($second->acquire());

        $first->release();

        static::assertTrue($second->acquire());
    }

    public function testUnwritableDirectoryNeverAcquires(): void
    {
        $lock = new FlushLock('/proc/jmonitor-nope', 'app', 10, new MockClock('@1000'));

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
}
