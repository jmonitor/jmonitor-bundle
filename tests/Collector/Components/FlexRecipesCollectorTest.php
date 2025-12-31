<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Jmonitor\JmonitorBundle\Collector\Components\FlexRecipesCollector;
use PHPUnit\Framework\TestCase;

class FlexRecipesCollectorTest extends TestCase
{
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

    public function testGetCacheTtl(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $collector = new FlexRecipesCollector($commandRunner, null, 123);
        static::assertSame(123, $collector->getCacheTtl());
    }

    public function testParseOutput(): void
    {
        $output = <<<TXT
              Outdated recipes.


             * symfony/ux-turbo (recipe not installed)
             * zenstruck/messenger-monitor-bundle (recipe not installed)

            Run:
             * composer recipes vendor/package to see details about a recipe.
             * composer recipes:update vendor/package to update that recipe.
        TXT;

        $commandRunner = $this->createMock(CommandRunner::class);
        $collector = new FlexRecipesCollector($commandRunner);

        $reflection = new \ReflectionClass(FlexRecipesCollector::class);
        $method = $reflection->getMethod('parseOutput');

        $result = $method->invoke($collector, $output);

        static::assertEquals(
            [
                'symfony/ux-turbo (recipe not installed)',
                'zenstruck/messenger-monitor-bundle (recipe not installed)',
            ],
            $result,
        );
    }
}
