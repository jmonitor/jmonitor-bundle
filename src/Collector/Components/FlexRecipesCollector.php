<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;

class FlexRecipesCollector implements ComponentCollectorInterface
{
    private CommandRunner $commandRunner;

    public function __construct(CommandRunner $commandRunner)
    {
        $this->commandRunner = $commandRunner;
    }

    public function collect(): array
    {
        $run = $this->commandRunner->runProcess(['composer', 'recipes', '-o']);

        if ($run['exit_code'] !== 0) {
            return [];
        }

        return $this->parseOutput($run['output']);
    }

    private function parseOutput(?string $output): array
    {
        return [];
    }
}
