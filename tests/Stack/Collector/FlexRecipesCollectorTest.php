<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlexRecipesCollectorTest extends TestCase
{
    public function testItRunsComposerInTheProjectDirectory(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(['composer', 'recipes', '-o'], '/srv/app', 5)
            ->willReturn(['exit_code' => 0, 'output' => '', 'error_output' => '']);

        $collector = new FlexRecipesCollector($runner, '/srv/app');

        self::assertSame('symfony.flex', $collector->getName());
        self::assertSame(['up_to_date' => true, 'outdated_recipes' => []], $collector->collect());
    }

    public function testItListsTheOutdatedRecipes(): void
    {
        $output = <<<TXT
                  Outdated recipes.


                 * symfony/ux-turbo (recipe not installed)
                 * zenstruck/messenger-monitor-bundle (recipe not installed)

                Run:
                 * composer recipes vendor/package to see details about a recipe.
                 * composer recipes:update vendor/package to update that recipe.
            TXT;

        self::assertSame(
            [
                'up_to_date' => false,
                'outdated_recipes' => [
                    'symfony/ux-turbo (recipe not installed)',
                    'zenstruck/messenger-monitor-bundle (recipe not installed)',
                ],
            ],
            $this->collector(['exit_code' => 1, 'output' => $output, 'error_output' => ''])->collect(),
        );
    }

    /**
     * @return iterable<string, array{array{exit_code: ?int, output: string, error_output: string}, string}>
     */
    public static function failures(): iterable
    {
        yield 'error output' => [
            ['exit_code' => 1, 'output' => '', 'error_output' => "The HOME or COMPOSER_HOME environment variable must be set for composer to run correctly\n"],
            'composer recipes failed (exit code: 1): The HOME or COMPOSER_HOME environment variable must be set for composer to run correctly',
        ];
        yield 'no error output' => [
            ['exit_code' => 1, 'output' => 'Command "recipes" is not defined.', 'error_output' => ''],
            'composer recipes failed (exit code: 1): Command "recipes" is not defined.',
        ];
    }

    /**
     * @param array{exit_code: ?int, output: string, error_output: string} $run
     */
    #[DataProvider('failures')]
    public function testAFailureIsNotTakenForOutdatedRecipes(array $run, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->collector($run)->collect();
    }

    public function testAMissingComposerIsAMissingPrerequisite(): void
    {
        $runner = $this->createStub(ProcessRunner::class);
        $runner->method('run')->willThrowException(new MissingPrerequisiteException('"composer" cannot be found.'));

        $this->expectException(MissingPrerequisiteException::class);

        (new FlexRecipesCollector($runner, '/srv/app'))->collect();
    }

    /**
     * @param array{exit_code: ?int, output: string, error_output: string} $run
     */
    private function collector(array $run): FlexRecipesCollector
    {
        $runner = $this->createStub(ProcessRunner::class);
        $runner->method('run')->willReturn($run);

        return new FlexRecipesCollector($runner, '/srv/app');
    }
}
