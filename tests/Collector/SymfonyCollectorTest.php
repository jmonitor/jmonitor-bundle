<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector;

use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use Jmonitor\JmonitorBundle\Collector\Components\ComponentCollectorInterface;
use Jmonitor\JmonitorBundle\Collector\SymfonyCollector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
        static::assertSame('/cache/dir', $result['cache_dir']);

        static::assertArrayHasKey('log_dir', $result);
        static::assertSame('/log/dir', $result['log_dir']);

        static::assertArrayHasKey('build_dir', $result);
        static::assertSame('/build/dir', $result['build_dir']);

        static::assertArrayHasKey('components', $result);
        static::assertSame(['comp1' => ['foo' => 'bar']], $result['components']);
    }

    public function testUnexpectedBootExceptionDisablesOnlyThatComponent(): void
    {
        $broken = $this->createMock(ComponentCollectorInterface::class);
        $broken->method('boot')->willThrowException(new \RuntimeException('boom'));
        $broken->expects(static::never())->method('collect');

        $healthy = $this->createMock(ComponentCollectorInterface::class);
        $healthy->expects(static::once())->method('boot');
        $healthy->method('collect')->willReturn(['foo' => 'bar']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())
            ->method('error')
            ->with(
                static::stringContains('component disabled until worker restart'),
                static::callback(fn(array $context) => $context['component'] === 'broken' && $context['message'] === 'boom'),
            );

        $collector = new SymfonyCollector($this->kernel, ['broken' => $broken, 'healthy' => $healthy]);
        $collector->setLogger($logger);

        $collector->boot();
        $result = $collector->collect();

        static::assertSame(['healthy' => ['foo' => 'bar']], $result['components']);
    }

    public function testBootFailedExceptionDisablesOnlyThatComponent(): void
    {
        $broken = $this->createMock(ComponentCollectorInterface::class);
        $broken->method('boot')->willThrowException(
            new BootFailedException('wrapped', new CollectorException('unable to run', 'broken')),
        );
        $broken->expects(static::never())->method('collect');

        $healthy = $this->createMock(ComponentCollectorInterface::class);
        $healthy->method('collect')->willReturn(['foo' => 'bar']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())
            ->method('error')
            ->with(
                static::anything(),
                static::callback(fn(array $context) => str_contains($context['message'], 'unable to run') && $context['exception'] instanceof CollectorException),
            );

        $collector = new SymfonyCollector($this->kernel, ['broken' => $broken, 'healthy' => $healthy]);
        $collector->setLogger($logger);

        $collector->boot();

        static::assertSame(['healthy' => ['foo' => 'bar']], $collector->collect()['components']);
    }

    public function testUnexpectedCollectExceptionDoesNotBreakOtherComponents(): void
    {
        $broken = $this->createMock(ComponentCollectorInterface::class);
        $broken->expects(static::exactly(2))->method('collect')->willThrowException(new \RuntimeException('boom'));

        $healthy = $this->createMock(ComponentCollectorInterface::class);
        $healthy->method('collect')->willReturn(['foo' => 'bar']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::exactly(2))
            ->method('error')
            ->with(static::anything(), static::callback(fn(array $context) => $context['component'] === 'broken'));

        $collector = new SymfonyCollector($this->kernel, ['broken' => $broken, 'healthy' => $healthy]);
        $collector->setLogger($logger);

        static::assertSame(['healthy' => ['foo' => 'bar']], $collector->collect()['components']);
        static::assertSame(['healthy' => ['foo' => 'bar']], $collector->collect()['components']);
    }

    public function testGetVersion(): void
    {
        $collector = new SymfonyCollector($this->kernel, []);
        static::assertSame(1, $collector->getVersion());
    }
}
