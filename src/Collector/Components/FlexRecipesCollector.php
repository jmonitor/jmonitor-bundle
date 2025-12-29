<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;

final class FlexRecipesCollector implements ComponentCollectorInterface
{
    private CommandRunner $commandRunner;
    private ?string $command;

    public function __construct(CommandRunner $commandRunner, ?string $command = null)
    {
        $this->commandRunner = $commandRunner;
        $this->command = $command;
    }

    /**
     *
     */
    public function collect(): array
    {
        $command = $this->command ?? ['composer', 'recipes', '-o'];
        $run = $this->commandRunner->runProcess($command);

        if ($run['exit_code'] === 0) {
            return [
                'up_to_date' => true,
            ];
        }

        return [
            'up_to_date' => false,
            'recipes' => $this->parseOutput($run['output']),
        ];
    }

    private function parseOutput(string $output): array
    {
        return [];
    }
}
