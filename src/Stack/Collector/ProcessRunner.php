<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Symfony\Component\Process\Exception\LogicException as ProcessLogicException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

class ProcessRunner
{
    private const ENV = ['SHELL_VERBOSITY' => '0', 'COMPOSER_NO_INTERACTION' => '1'];

    /**
     * @param list<string>          $command
     * @param array<string, string> $env     added to the environment of the command
     *
     * @return array{exit_code: ?int, output: string, error_output: string}
     *
     * @throws MissingPrerequisiteException the command cannot be started, or exits with 126 or 127
     * @throws \RuntimeException            it ran longer than $timeout seconds
     */
    public function run(array $command, string $workingDirectory, int $timeout, array $env = []): array
    {
        $process = self::start($command, $workingDirectory, $timeout, $env);
        $exitCode = $process->wait();
        self::assertRunnable($command, $exitCode, $process);

        return ['exit_code' => $process->getExitCode(), 'output' => $process->getOutput(), 'error_output' => $process->getErrorOutput()];
    }

    /**
     * Never throws on a timeout: gives what the command printed until it was stopped.
     *
     * @param list<string>          $command
     * @param array<string, string> $env     added to the environment of the command
     *
     * @return array{exit_code: ?int, output: string, error_output: string, timed_out: bool}
     *
     * @throws MissingPrerequisiteException the command cannot be started, or exits with 126 or 127
     */
    public function runUntil(array $command, string $workingDirectory, int $timeout, array $env = []): array
    {
        $process = self::start($command, $workingDirectory, $timeout, $env);

        try {
            self::assertRunnable($command, $process->wait(), $process);
            $timedOut = false;
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        return ['exit_code' => $process->getExitCode(), 'output' => $process->getOutput(), 'error_output' => $process->getErrorOutput(), 'timed_out' => $timedOut];
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    private static function start(array $command, string $workingDirectory, int $timeout, array $env): Process
    {
        try {
            $process = new Process($command, $workingDirectory, [...self::ENV, ...$env], null, $timeout);
            $process->start();
        } catch (ProcessLogicException|ProcessRuntimeException $e) {
            throw new MissingPrerequisiteException(sprintf('"%s" cannot be started: %s', $command[0] ?? '', $e->getMessage()), 0, $e);
        }

        return $process;
    }

    /**
     * @param list<string> $command
     */
    private static function assertRunnable(array $command, int $exitCode, Process $process): void
    {
        if ($exitCode === 126 || $exitCode === 127) {
            throw new MissingPrerequisiteException(sprintf('"%s" cannot be run (exit code: %d): %s', $command[0] ?? '', $exitCode, trim($process->getErrorOutput())));
        }
    }
}
