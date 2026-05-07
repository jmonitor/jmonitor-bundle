<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\Exceptions\CollectorException;
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
    private ?string $command;
    private int $timeout;

    public function __construct(CommandRunner $commandRunner, ?string $command = null, int $timeout = 3)
    {
        $this->commandRunner = $commandRunner;
        $this->command = $command;
        $this->timeout = $timeout;
    }

    public function collect(): array
    {
        if ($this->command !== null) {
            $run = $this->commandRunner->runProcess($this->command, $this->timeout);
        } else {
            $run = $this->commandRunner->runPhpProcess(['bin/console', 'messenger:stats', '--format=json'], $this->timeout);
        }

        if ($run['exit_code'] !== 0) {
            throw new CollectorException('messenger:stats command failed (exit code: ' . var_export($run['exit_code'], true) . ')', __CLASS__);
        }

        try {
            return json_decode($run['output'], true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CollectorException('Failed to decode messenger:stats output: ' . $e->getMessage(), __CLASS__, $e);
        }
    }
}
