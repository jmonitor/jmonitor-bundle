<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

/**
 * Run console commands and processes, return output and exit code.
 */
class CommandRunner
{
    private Application $application;

    private string $projectDir;

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
     * @param array<string> $command
     *
     * @return array{exit_code: int, output: string}
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
        ];
    }

    public function getApplication(): Application
    {
        return $this->application;
    }
}
