<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;

final readonly class FlexRecipesCollector implements CollectorInterface
{
    private const TIMEOUT = 5;

    public function __construct(
        private ProcessRunner $runner,
        private string $projectDir,
    ) {}

    /**
     * @return array{up_to_date: bool, outdated_recipes: list<string>}
     *
     * @throws MissingPrerequisiteException composer cannot be run
     * @throws \RuntimeException
     */
    public function collect(): array
    {
        $run = $this->runner->run(['composer', 'recipes', '-o'], $this->projectDir, self::TIMEOUT);

        if ($run['exit_code'] === 0) {
            return ['up_to_date' => true, 'outdated_recipes' => []];
        }

        if (!str_contains($run['output'], 'Outdated recipes.')) {
            $details = trim($run['error_output']) !== '' ? trim($run['error_output']) : trim($run['output']);
            $message = sprintf('composer recipes failed (exit code: %s)', var_export($run['exit_code'], true));

            throw new \RuntimeException($details === '' ? $message : $message . ': ' . $details);
        }

        return ['up_to_date' => false, 'outdated_recipes' => self::outdatedRecipes($run['output'])];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'symfony.flex';
    }

    /**
     * @return list<string>
     */
    private static function outdatedRecipes(string $output): array
    {
        $output = str_replace('Outdated recipes.', '', $output);
        $recipes = [];

        foreach (explode("\n", explode('Run:', $output)[0]) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '* ')) {
                $recipes[] = trim(substr($line, 2));
            }
        }

        return $recipes;
    }
}
