<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;

final class MessengerStatsCollector implements ComponentCollectorInterface
{
    private CommandRunner $commandRunner;

    public function __construct(CommandRunner $commandRunner)
    {
        $this->commandRunner = $commandRunner;
    }

    public function collect(): array
    {
        $run = $this->commandRunner->run('messenger:stats', ['--format' => 'json']);

        if ($run === null || $run['exit_code'] !== 0) {
            return [];
        }

        try {
            return json_decode($run['output'], true, 3, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }
}
