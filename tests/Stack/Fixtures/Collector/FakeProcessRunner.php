<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;

/**
 * Answers each command with what it was given for it, exits 126 and 127 like the real runner does,
 * and notes every call.
 */
final class FakeProcessRunner extends ProcessRunner
{
    /** @var list<array{command: list<string>, working_directory: string, timeout: int, env: array<string, string>}> */
    public array $calls = [];

    /** @var list<array{list<string>, array{exit_code: ?int, output: string, error_output: string}|\Throwable}> */
    private array $answers = [];

    /**
     * @param list<string> $command
     */
    public function answer(array $command, string $output = '', ?int $exitCode = 0, string $errorOutput = ''): self
    {
        $this->answers[] = [$command, ['exit_code' => $exitCode, 'output' => $output, 'error_output' => $errorOutput]];

        return $this;
    }

    /**
     * @param list<string> $command
     */
    public function fail(array $command, \Throwable $failure): self
    {
        $this->answers[] = [$command, $failure];

        return $this;
    }

    public function run(array $command, string $workingDirectory, int $timeout, array $env = []): array
    {
        $this->calls[] = ['command' => $command, 'working_directory' => $workingDirectory, 'timeout' => $timeout, 'env' => $env];

        foreach (array_reverse($this->answers) as [$expected, $answer]) {
            if ($expected !== $command) {
                continue;
            }

            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            if ($answer['exit_code'] === 126 || $answer['exit_code'] === 127) {
                throw new MissingPrerequisiteException(sprintf('"%s" cannot be run (exit code: %d): %s', $command[0], $answer['exit_code'], trim($answer['error_output'])));
            }

            return $answer;
        }

        throw new \LogicException(sprintf('No answer for the command %s.', json_encode($command)));
    }
}
