<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;

final class SchedulerCollector implements ComponentCollectorInterface
{
    private CommandRunner $commandRunner;

    public function __construct(CommandRunner $commandRunner)
    {
        $this->commandRunner = $commandRunner;
    }

    public function collect(): array
    {
        $run = $this->commandRunner->run('debug:scheduler');

        if ($run === null || $run['exit_code'] !== 0) {
            return [];
        }

        $commands = $this->parseOutput($run['output']);

        // ajoute la description de chaque commande
        foreach ($commands as &$command) {
            $command['description'] = $this->commandRunner->getApplication()->find($command['command'])->getDescription();
        }

        return $commands;
    }

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
            if (preg_match('/^(.+?)\s+Symfony\\\\Component\\\\Console\\\\Messenger\\\\RunCommandMessage\s+\((.+?)\)\s+(.+)$/', $line, $matches)) {
                $trigger = trim($matches[1]);
                $command = trim($matches[2]);
                $nextRunStr = trim($matches[3]);

                $nextRun = null;
                try {
                    $date = new \DateTimeImmutable($nextRunStr);
                    $nextRun = $date->getTimestamp();
                } catch (\Exception) {
                }

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
