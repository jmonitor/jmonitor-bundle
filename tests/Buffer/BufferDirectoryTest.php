<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Buffer;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Filesystem\Filesystem;

final class BufferDirectoryTest extends TestCase
{
    private string $dir;
    private string $appId;
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-buffer-directory-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->appId = 'test-' . bin2hex(random_bytes(6));
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
        chmod($this->dir, 0o700);
        (new Filesystem())->remove([$this->dir, $this->fallback()]);
    }

    public function testAMissingConfiguredDirectoryIsNotCreated(): void
    {
        $path = (new BufferDirectory($this->dir . '/missing', $this->appId))->path();

        static::assertSame($this->fallback(), $path);
        static::assertStringStartsWith(sys_get_temp_dir(), (string) $path);
        static::assertFileDoesNotExist($this->dir . '/missing');
    }

    public function testTheFallbackIsLoggedOncePerFallbackDirectory(): void
    {
        (new BufferDirectory($this->dir . '/missing', $this->appId, $this->logger))->path();
        (new BufferDirectory($this->dir . '/missing', $this->appId, $this->logger))->path();

        static::assertSame([
            'warning The configured buffer directory "' . $this->dir . '/missing" does not exist: JMonitor buffers in the system temporary directory instead.',
        ], $this->logger->records);
    }

    public function testTheFallbackIsLoggedEvenWhenItsDirectoryAlreadyExisted(): void
    {
        mkdir($this->fallback(), 0o700);

        $first = (new BufferDirectory($this->dir . '/missing', $this->appId, $this->logger))->path();
        (new BufferDirectory($this->dir . '/missing', $this->appId, $this->logger))->path();

        static::assertSame($this->fallback(), $first);
        static::assertSame([
            'warning The configured buffer directory "' . $this->dir . '/missing" does not exist: JMonitor buffers in the system temporary directory instead.',
        ], $this->logger->records);
    }

    public function testWithoutConfiguredDirectoryNothingIsLoggedNorMarked(): void
    {
        $path = (new BufferDirectory(null, $this->appId, $this->logger))->path();

        static::assertSame($this->fallback(), $path);
        static::assertSame([], $this->logger->records);
        static::assertSame([], array_values(array_diff(scandir((string) $path) ?: [], ['.', '..'])));
    }

    public function testALoggerGivenAsAClosureIsOnlyResolvedToLog(): void
    {
        $calls = 0;
        $logger = function () use (&$calls): AbstractLogger {
            ++$calls;

            return $this->logger;
        };

        (new BufferDirectory($this->dir, $this->appId, $logger))->path();
        static::assertSame(0, $calls);

        (new BufferDirectory($this->dir . '/missing', $this->appId, $logger))->path();
        static::assertSame(1, $calls);
        static::assertCount(1, $this->logger->records);
    }

    public function testAnExistingConfiguredDirectoryIsUsedAndNothingIsLogged(): void
    {
        $path = (new BufferDirectory($this->dir, $this->appId, $this->logger))->path();

        static::assertSame($this->dir . '/jmonitor-' . $this->appId . '-u' . posix_geteuid(), $path);
        static::assertSame(0o700, fileperms((string) $path) & 0o777);
        static::assertSame([], $this->logger->records);
        static::assertFileDoesNotExist($this->fallback());
        static::assertSame([], array_values(array_diff(scandir((string) $path) ?: [], ['.', '..'])));
    }

    public function testAConfiguredDirectoryThatCannotBeWrittenIsRefusedWithoutFallback(): void
    {
        if (posix_geteuid() === 0) {
            static::markTestSkipped('Root writes to any directory.');
        }
        chmod($this->dir, 0o500);

        static::assertNull((new BufferDirectory($this->dir, $this->appId, $this->logger))->path());
        static::assertFileDoesNotExist($this->fallback());
        static::assertSame([], $this->logger->records);
    }

    private function fallback(): string
    {
        return sys_get_temp_dir() . '/jmonitor-' . $this->appId . '-u' . posix_geteuid();
    }
}
