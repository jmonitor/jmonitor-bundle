<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;

/**
 * Collects the messenger stats via the messenger:stats command
 *
 * To avoid "Mysql server has gone away" errors, the command is run in a separate process
 * (a "php bin/console messenger:stats" instead of using the Application::doRun() method)
 */
final class MessengerStatsCollector implements ComponentCollectorInterface
{
    private CommandRunner $commandRunner;

    public function __construct(CommandRunner $commandRunner)
    {
        $this->commandRunner = $commandRunner;
    }

    public function collect(): array
    {
        // TODO make bin/console (or the whole command ?) configurable
        $run = $this->commandRunner->runPhpProcess(['bin/console', 'messenger:stats', '--format=json']);

        if ($run['exit_code'] !== 0) {
            return [];
        }

        try {
            $decoded = json_decode($run['output'], true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
