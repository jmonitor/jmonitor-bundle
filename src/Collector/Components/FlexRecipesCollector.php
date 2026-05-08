<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Symfony\Component\Process\Exception\RuntimeException;

final class FlexRecipesCollector implements ComponentCollectorInterface
{
    private CommandRunner $commandRunner;
    private ?string $command;
    private ?int $timeout;

    // TODO array y-m-d
    private ?array $propertyCache = null;

    public function __construct(CommandRunner $commandRunner, ?string $command = null, ?int $timeout = 5)
    {
        $this->commandRunner = $commandRunner;
        $this->command = $command;
        $this->timeout = $timeout;
    }

    public function boot(): void
    {
        try {
            $this->collect();
        } catch (CollectorException $e) {
            throw new BootFailedException($e->getMessage(), $e);
        }
    }

    /**
     * @return array{up_to_date: true}|array{up_to_date: false, outdated_recipes: array<string>}
     */
    public function collect(): array
    {
        if ($this->propertyCache !== null) {
            return $this->propertyCache;
        }

        $command = $this->command ?? ['composer', 'recipes', '-o'];

        try {
            $run = $this->commandRunner->runProcess($command, $this->timeout);
        } catch (RuntimeException $e) {
            throw new CollectorException($e->getMessage(), __CLASS__, $e);
        }

        if ($run['exit_code'] === 0) {
            return $this->propertyCache = [
                'up_to_date' => true,
            ];
        }

        if ($run['exit_code'] === null) {
            throw new CollectorException('Unable to run flex recipe command', __CLASS__);
        }

        return $this->propertyCache = [
            'up_to_date' => false,
            'outdated_recipes' => $this->parseOutput($run['output']),
        ];
    }

    private function parseOutput(string $output): array
    {
        $output = str_replace('Outdated recipes.', '', $output);
        $output = explode('Run:', $output)[0];
        $output = trim($output);

        if (empty($output)) {
            return [];
        }

        $lines = explode("\n", $output);
        $recipes = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '* ')) {
                $recipes[] = trim(substr($line, 2));
            }
        }

        return $recipes;
    }
}
