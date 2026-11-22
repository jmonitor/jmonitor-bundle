<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\KernelCollector;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

final class KernelCollectorTest extends TestCase
{
    public function testItDescribesTheKernel(): void
    {
        $kernel = new TestKernel('test', true, withDoctrine: false, withHttpClient: false);
        $kernel->boot();

        try {
            $collector = new KernelCollector($kernel);
            $data = $collector->collect();
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($kernel->getVarDir());
        }

        self::assertSame('symfony', $collector->getName());
        self::assertSame(['env', 'debug', 'version', 'bundles'], array_keys($data));
        self::assertSame('test', $data['env']);
        self::assertTrue($data['debug']);
        self::assertSame(Kernel::VERSION, $data['version']);
        self::assertContains('FrameworkBundle', $data['bundles']);
        self::assertContains('JmonitorBundle', $data['bundles']);
        self::assertTrue(array_is_list($data['bundles']));
    }
}
