<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Symfony\Component\Process\Exception\LogicException as ProcessLogicException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

class ProcessRunner
{
    /**
     * @param list<string> $command
     *
     * @return array{exit_code: ?int, output: string, error_output: string}
     *
     * @throws MissingPrerequisiteException the command cannot be started, or exits with 126 or 127
     * @throws \RuntimeException            it ran longer than $timeout seconds
     */
    public function run(array $command, string $workingDirectory, int $timeout): array
    {
        try {
            $process = new Process($command, $workingDirectory, ['SHELL_VERBOSITY' => '0', 'COMPOSER_NO_INTERACTION' => '1'], null, $timeout);
            $process->start();
        } catch (ProcessLogicException|ProcessRuntimeException $e) {
            throw new MissingPrerequisiteException(sprintf('"%s" cannot be started: %s', $command[0] ?? '', $e->getMessage()), 0, $e);
        }

        $exitCode = $process->wait();

        if ($exitCode === 126 || $exitCode === 127) {
            throw new MissingPrerequisiteException(sprintf('"%s" cannot be run (exit code: %d): %s', $command[0] ?? '', $exitCode, trim($process->getErrorOutput())));
        }

        return ['exit_code' => $process->getExitCode(), 'output' => $process->getOutput(), 'error_output' => $process->getErrorOutput()];
    }
}
