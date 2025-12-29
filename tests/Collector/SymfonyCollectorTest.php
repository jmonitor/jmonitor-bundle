<?php

/*
 * This file is part of the jmonitor/jmonitor-bundle package.
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector;

use Jmonitor\JmonitorBundle\Collector\Components\ComponentCollectorInterface;
use Jmonitor\JmonitorBundle\Collector\SymfonyCollector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

class SymfonyCollectorTest extends TestCase
{
    private KernelInterface|MockObject $kernel;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(KernelInterface::class);
    }

    public function testCollect(): void
    {
        $this->kernel->method('getEnvironment')->willReturn('test_env');
        $this->kernel->method('isDebug')->willReturn(true);
        $this->kernel->method('getBundles')->willReturn(['FrameworkBundle' => [], 'JmonitorBundle' => []]);
        $this->kernel->method('getProjectDir')->willReturn('/project/dir');
        $this->kernel->method('getCacheDir')->willReturn('/cache/dir');
        $this->kernel->method('getLogDir')->willReturn('/log/dir');
        $this->kernel->method('getBuildDir')->willReturn('/build/dir');
        $this->kernel->method('getCharset')->willReturn('UTF-8');

        $component1 = $this->createMock(ComponentCollectorInterface::class);
        $component1->method('collect')->willReturn(['foo' => 'bar']);

        $componentCollectors = [
            'comp1' => $component1,
        ];

        $collector = new SymfonyCollector($this->kernel, $componentCollectors);

        $result = $collector->collect();

        static::assertSame('test_env', $result['env']);
        static::assertTrue($result['debug']);
        static::assertSame(['FrameworkBundle', 'JmonitorBundle'], $result['bundles']);
        static::assertSame('/project/dir', $result['project_dir']);
        static::assertSame('UTF-8', $result['charset']);

        static::assertArrayHasKey('cache_dir', $result);
        static::assertSame('/cache/dir', $result['cache_dir']['path']);

        static::assertArrayHasKey('log_dir', $result);
        static::assertSame('/log/dir', $result['log_dir']['path']);

        static::assertArrayHasKey('build_dir', $result);
        static::assertSame('/build/dir', $result['build_dir']['path']);

        static::assertArrayHasKey('components', $result);
        static::assertSame(['comp1' => ['foo' => 'bar']], $result['components']);
    }

    public function testGetVersion(): void
    {
        $collector = new SymfonyCollector($this->kernel, []);
        static::assertSame(1, $collector->getVersion());
    }
}
