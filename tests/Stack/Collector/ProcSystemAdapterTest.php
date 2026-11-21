<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\ProcSystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcSystemAdapterTest extends TestCase
{
    private const MIB = 1024 * 1024;

    /**
     * @return iterable<string, array{string, int, int, ?int, ?float}>
     */
    public static function fileSets(): iterable
    {
        yield 'no cgroup limit' => ['bare', 8192 * self::MIB, 4096 * self::MIB, null, null];
        yield 'cgroup v2' => ['cgroup-v2', 512 * self::MIB, 312 * self::MIB, 512 * self::MIB, 1.5];
        yield 'cgroup v1' => ['cgroup-v1', 512 * self::MIB, 312 * self::MIB, 512 * self::MIB, 1.5];
        yield 'cgroup v2 without limit' => ['unlimited', 8192 * self::MIB, 4096 * self::MIB, null, null];
    }

    #[DataProvider('fileSets')]
    public function testTheMemoryAndTheCoresFollowTheFiles(string $set, int $total, int $available, ?int $memoryLimit, ?float $cpuQuota): void
    {
        $adapter = self::adapter($set);

        self::assertSame($total, $adapter->getTotalMemory());
        self::assertSame($available, $adapter->getAvailableMemory());
        self::assertSame(4, $adapter->getCoreCount());
        self::assertSame($memoryLimit, $adapter->memoryLimit());
        self::assertSame($cpuQuota, $adapter->cpuQuota());
    }

    public function testNoCommandIsRun(): void
    {
        $adapter = self::adapter('cgroup-v2');

        $adapter->getTotalMemory();
        $adapter->getAvailableMemory();
        $adapter->getCoreCount();
        $adapter->getLoadPercent();
        $adapter->memoryLimit();
        $adapter->cpuQuota();
        $adapter->reset();
        $adapter->getTotalMemory();

        $this->addToAssertionCount(1);
    }

    public function testUnreadableFilesGiveNullsWithoutWarning(): void
    {
        $adapter = new ProcSystemAdapter('/jmonitor-no-such-dir/proc', '/jmonitor-no-such-dir/cgroup');

        set_error_handler(static function (int $type, string $message): never {
            throw new \ErrorException($message, 0, $type);
        });

        try {
            self::assertNull($adapter->getTotalMemory());
            self::assertNull($adapter->getAvailableMemory());
            self::assertNull($adapter->getCoreCount());
            self::assertNull($adapter->memoryLimit());
            self::assertNull($adapter->cpuQuota());
        } finally {
            restore_error_handler();
        }
    }

    private static function adapter(string $set): ProcSystemAdapter
    {
        $dir = \dirname(__DIR__) . '/Fixtures/proc/' . $set;

        return new ProcSystemAdapter($dir . '/proc', $dir . '/cgroup');
    }
}
