<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Jmonitor\JmonitorBundle\Collector\Components\MessengerStatsCollector;
use PHPUnit\Framework\TestCase;

class MessengerStatsCollectorTest extends TestCase
{
    public function testCollectWithDefaultCommandUsesRunPhpProcess(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runPhpProcess')
            ->with(['bin/console', 'messenger:stats', '--format=json'], 3)
            ->willReturn(['exit_code' => 0, 'output' => '[]', 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner);
        $result = $collector->collect();

        static::assertSame([], $result);
    }

    public function testCollectWithCustomCommandUsesRunProcess(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->with('/usr/bin/php bin/console messenger:stats --format=json', 5)
            ->willReturn(['exit_code' => 0, 'output' => '[]', 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner, '/usr/bin/php bin/console messenger:stats --format=json', 5);
        $result = $collector->collect();

        static::assertSame([], $result);
    }

    public function testCollectReturnsEmptyArrayOnNonZeroExitCode(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->method('runPhpProcess')
            ->willReturn(['exit_code' => 1, 'output' => '', 'error_output' => 'error']);

        $collector = new MessengerStatsCollector($commandRunner);
        static::assertSame([], $collector->collect());
    }

    public function testCollectReturnsEmptyArrayOnInvalidJson(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->method('runPhpProcess')
            ->willReturn(['exit_code' => 0, 'output' => 'not-json', 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner);
        static::assertSame([], $collector->collect());
    }

    public function testCollectReturnsDecodedArray(): void
    {
        $data = [
            ['name' => 'async', 'messages' => 42],
            ['name' => 'failed', 'messages' => 0],
        ];

        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->method('runPhpProcess')
            ->willReturn(['exit_code' => 0, 'output' => json_encode($data), 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner);
        static::assertSame($data, $collector->collect());
    }

    public function testCollectReturnsEmptyArrayWhenJsonIsNotAnArray(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->method('runPhpProcess')
            ->willReturn(['exit_code' => 0, 'output' => '"string"', 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner);
        static::assertSame([], $collector->collect());
    }
}
