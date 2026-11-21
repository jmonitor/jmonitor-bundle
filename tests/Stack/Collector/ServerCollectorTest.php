<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\ProcSystemAdapter;
use Jmonitor\JmonitorBundle\Stack\Collector\ServerCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ServerCollectorTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            (new Filesystem())->remove($this->dir);
        }
    }

    public function testItAddsTheCgroupToTheSystemData(): void
    {
        $dir = \dirname(__DIR__) . '/Fixtures/proc/cgroup-v2';
        $collector = new ServerCollector(new ProcSystemAdapter($dir . '/proc', $dir . '/cgroup'));

        $data = $collector->collect();

        self::assertSame('system', $collector->getName());
        foreach (['disk', 'cpu', 'ram', 'os', 'cgroup'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
        self::assertSame(['memory_limit' => 512 * 1024 * 1024, 'cpu_quota' => 1.5], $data['cgroup']);
        self::assertSame(4, $data['cpu']['cores']);
    }

    public function testResetEmptiesTheAdapter(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-server-collector-' . bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->mirror(\dirname(__DIR__) . '/Fixtures/proc/cgroup-v2', $this->dir);
        $collector = new ServerCollector(new ProcSystemAdapter($this->dir . '/proc', $this->dir . '/cgroup'));

        self::assertSame(512 * 1024 * 1024, $collector->collect()['cgroup']['memory_limit']);

        $filesystem->dumpFile($this->dir . '/cgroup/memory.max', "268435456\n");
        self::assertSame(512 * 1024 * 1024, $collector->collect()['cgroup']['memory_limit']);

        $collector->reset();
        self::assertSame(256 * 1024 * 1024, $collector->collect()['cgroup']['memory_limit']);
    }
}
