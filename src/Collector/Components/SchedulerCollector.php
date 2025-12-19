<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;

class SchedulerCollector implements ComponentCollectorInterface
{
    private CommandRunner $commandRunner;

    public function __construct(CommandRunner $commandRunner)
    {
        $this->commandRunner = $commandRunner;
    }

    public function collect(): array
    {
        return $this->parseOutput($this->commandRunner->run('debug:scheduler'));
    }

    private function parseOutput(?string $output): array
    {
        if ($output === null) {
            return [];
        }

        return ['todo'];
    }
}
