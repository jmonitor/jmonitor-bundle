<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector\Components;

use Jmonitor\Exceptions\CollectorException;
use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Jmonitor\JmonitorBundle\Collector\Components\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\Collector\SymfonyCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

class FlexRecipesCollectorTest extends TestCase
{
    private const OUTDATED_OUTPUT = <<<TXT
              Outdated recipes.


             * symfony/ux-turbo (recipe not installed)
             * zenstruck/messenger-monitor-bundle (recipe not installed)

            Run:
             * composer recipes vendor/package to see details about a recipe.
             * composer recipes:update vendor/package to update that recipe.
        TXT;

    public function testCollectWithDefaultCommand(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->with(['composer', 'recipes', '-o'])
            ->willReturn(['exit_code' => 0, 'output' => '']);

        $collector = new FlexRecipesCollector($commandRunner);
        $collector->collect();
    }

    public function testCollectWithCustomCommand(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->with('php bin/console custom:recipes')
            ->willReturn(['exit_code' => 0, 'output' => '']);

        $collector = new FlexRecipesCollector($commandRunner, 'php bin/console custom:recipes');
        $collector->collect();
    }

    public function testBootRunsNoCommand(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner->expects($this->never())->method('runProcess');

        (new FlexRecipesCollector($commandRunner))->boot();
    }

    public function testUpToDateIsCachedForTheDay(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->willReturn(['exit_code' => 0, 'output' => '', 'error_output' => '']);

        $collector = new FlexRecipesCollector($commandRunner);

        static::assertSame(['up_to_date' => true], $collector->collect());
        static::assertSame(['up_to_date' => true], $collector->collect());
    }

    public function testOutdatedIsCachedForTheDay(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->willReturn(['exit_code' => 1, 'output' => self::OUTDATED_OUTPUT, 'error_output' => '']);

        $collector = new FlexRecipesCollector($commandRunner);
        $expected = [
            'up_to_date' => false,
            'outdated_recipes' => [
                'symfony/ux-turbo (recipe not installed)',
                'zenstruck/messenger-monitor-bundle (recipe not installed)',
            ],
        ];

        static::assertSame($expected, $collector->collect());
        static::assertSame($expected, $collector->collect());
    }

    public function testTimeoutIsAWarningAndIsNotRetriedBeforeTheDelay(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->willThrowException($this->timeout());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $logger->expects($this->once())
            ->method('warning')
            ->with(static::stringContains('exceeded the timeout'));

        $symfonyCollector = new SymfonyCollector(
            $this->createMock(KernelInterface::class),
            ['flex_recipes' => new FlexRecipesCollector($commandRunner)],
        );
        $symfonyCollector->setLogger($logger);

        $symfonyCollector->boot();

        static::assertArrayNotHasKey('flex_recipes', $symfonyCollector->collect()['components']);
        static::assertArrayNotHasKey('flex_recipes', $symfonyCollector->collect()['components']);
    }

    public function testTimeoutIsRetriedAfterTheDelay(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->exactly(2))
            ->method('runProcess')
            ->willReturnOnConsecutiveCalls(
                static::throwException($this->timeout()),
                ['exit_code' => 0, 'output' => '', 'error_output' => ''],
            );

        $collector = new FlexRecipesCollector($commandRunner);

        try {
            $collector->collect();
            static::fail('A CollectorException was expected.');
        } catch (CollectorException) {
        }

        (new \ReflectionProperty(FlexRecipesCollector::class, 'retryAfter'))->setValue($collector, time() - 1);

        static::assertSame(['up_to_date' => true], $collector->collect());
    }

    public function testFailedCommandIsNotReportedAsOutdated(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->willReturn(['exit_code' => 1, 'output' => '', 'error_output' => 'The "https://flex.symfony.com" file could not be downloaded']);

        $collector = new FlexRecipesCollector($commandRunner);

        try {
            $collector->collect();
            static::fail('A CollectorException was expected.');
        } catch (CollectorException $e) {
            static::assertStringContainsString('could not be downloaded', $e->getMessage());
        }

        static::assertSame([], $collector->collect());
    }

    public function testCommandNotFoundDisablesTheCollector(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->willReturn(['exit_code' => 127, 'output' => '', 'error_output' => 'sh: 1: composer: not found']);

        $collector = new FlexRecipesCollector($commandRunner);

        try {
            $collector->collect();
            static::fail('A CollectorException was expected.');
        } catch (CollectorException $e) {
            static::assertStringContainsString('disabled', $e->getMessage());
        }

        (new \ReflectionProperty(FlexRecipesCollector::class, 'retryAfter'))->setValue($collector, time() - 1);

        static::assertSame([], $collector->collect());
    }

    public function testProcessThatCannotStartDisablesTheCollector(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->willThrowException(new RuntimeException('Unable to launch a new process.'));

        $collector = new FlexRecipesCollector($commandRunner);

        try {
            $collector->collect();
            static::fail('A CollectorException was expected.');
        } catch (CollectorException) {
        }

        static::assertSame([], $collector->collect());
    }

    public function testParseOutput(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $collector = new FlexRecipesCollector($commandRunner);

        $reflection = new \ReflectionClass(FlexRecipesCollector::class);
        $method = $reflection->getMethod('parseOutput');

        $result = $method->invoke($collector, self::OUTDATED_OUTPUT);

        static::assertEquals(
            [
                'symfony/ux-turbo (recipe not installed)',
                'zenstruck/messenger-monitor-bundle (recipe not installed)',
            ],
            $result,
        );
    }

    private function timeout(): ProcessTimedOutException
    {
        return new ProcessTimedOutException(new Process(['composer', 'recipes', '-o'], timeout: 5), ProcessTimedOutException::TYPE_GENERAL);
    }
}
