<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Run console commands and processes, return output and exit code.
 *
 * Error handling should be improved (bundle wide) in the future.
 */
class CommandRunner
{
    private Application $application;

    private string $projectDir;

    // property cache
    private string|false|null $phpBinary = null;

    public function __construct(KernelInterface $kernel)
    {
        $this->application = new Application(clone $kernel);
        $this->application->setAutoExit(false);
        $this->projectDir = $kernel->getProjectDir();
    }

    /**
     * @return array{exit_code: int, output: string}|null
     */
    public function run(string $command, array $input = []): ?array
    {
        if (!$this->application->has($command)) {
            return null;
        }

        $input = array_merge(['command' => $command], $input);

        $output = new BufferedOutput();

        return [
            'exit_code' => $this->application->doRun(new ArrayInput($input), $output),
            'output' => $output->fetch(),
        ];
    }

    /**
     * @return array{exit_code: int|null, output: string, error_output: string}
     */
    public function runProcess(array|string $command, ?int $timeout = 3): array
    {
        $process = is_array($command)
            ? new Process($command, $this->projectDir, timeout: $timeout)
            : Process::fromShellCommandline($command, $this->projectDir, timeout: $timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode(),
            'output' => $process->getOutput(),
            'error_output' => $process->getErrorOutput(),
        ];
    }

    /**
     * Run a command using the PHP binary as the first argument,
     * So you don't have to include it yourself, and worry about the PHP binary path
     *
     * If the PHP binary cannot be found, it will fallback to runProcess()
     *
     * @return array{exit_code: int|null, output: string, error_output: string}
     */
    public function runPhpProcess(array|string $command, ?int $timeout = 3): array
    {
        if (!$phpBinary = $this->getPhpBinary()) {
            return $this->runProcess($command, $timeout);
        }

        if (is_array($command)) {
            return $this->runProcess([$phpBinary, ...$command], $timeout);
        }

        return $this->runProcess($phpBinary . ' ' . $command, $timeout);
    }

    public function getApplication(): Application
    {
        return $this->application;
    }

    private function getPhpBinary(): string|false
    {
        if ($this->phpBinary !== null) {
            return $this->phpBinary;
        }

        if (PHP_SAPI === 'frankenphp') {
            return $this->phpBinary = 'frankenphp php-cli';
        }

        return $this->phpBinary = (new PhpExecutableFinder())->find(false);
    }
}
