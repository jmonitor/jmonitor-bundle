<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use Jmonitor\JmonitorBundle\Collector\CommandRunner;

final class SchedulerCollector implements ComponentCollectorInterface
{
    private CommandRunner $commandRunner;
    private ?array $bootCollect = null;

    public function __construct(CommandRunner $commandRunner)
    {
        $this->commandRunner = $commandRunner;
    }

    public function boot(): void
    {
        try {
            $this->bootCollect = $this->collect();
        } catch (CollectorException $e) {
            throw new BootFailedException($e->getMessage(), $e);
        }
    }

    public function collect(): array
    {
        if ($this->bootCollect !== null) {
            $output = $this->bootCollect;
            $this->bootCollect = null;

            return $output;
        }

        $run = $this->commandRunner->run('debug:scheduler');

        if ($run === null) {
            throw new CollectorException('Unable to run debug:scheduler command', __CLASS__);
        }

        if ($run['exit_code'] !== 0) {
            throw new CollectorException('debug:scheduler command failed (exit code: ' . var_export($run['exit_code'], true) . ')', __CLASS__);
        }

        $commands = $this->parseOutput($run['output']);

        // ajoute la description de chaque commande
        foreach ($commands as &$command) {
            $command['description'] = $this->commandRunner
                ->getApplication()
                ->find($command['command'])
                ->getDescription();
        }

        return $commands;
    }

    /**
     * @return array<int, array{trigger: string, command: string, next_run: int}>
     */
    private function parseOutput(?string $output): array
    {
        if ($output === null) {
            return [];
        }

        $lines = explode("\n", $output);
        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '---') || str_starts_with($line, 'Trigger')) {
                continue;
            }

            // Regex plus précise pour les colonnes du tableau Symfony
            // Elle cherche à capturer le trigger, la commande entre parenthèses et la date à la fin.
            $matches = [];

            if (preg_match(
                '/^(.+?)\s+Symfony\\\\Component\\\\Console\\\\Messenger\\\\RunCommandMessage\s+\((.+?)\)\s+(.+)$/',
                $line,
                $matches,
            )) {
                $trigger = trim($matches[1]);
                $command = trim($matches[2]);
                $nextRunStr = trim($matches[3]);

                try {
                    $date = new \DateTimeImmutable($nextRunStr);
                } catch (\Exception) {
                    continue;
                }
                $nextRun = $date->getTimestamp();

                $data[] = [
                    'trigger' => $trigger,
                    'command' => $command,
                    'next_run' => $nextRun,
                ];
            }
        }

        return $data;
    }
}
