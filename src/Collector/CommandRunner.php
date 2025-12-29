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

/**
 * Run console commands and return output and exit code.
 */
class CommandRunner
{
    private Application $application;

    public function __construct(KernelInterface $kernel)
    {
        $this->application = new Application(clone $kernel);
        $this->application->setAutoExit(false);
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

    public function getApplication(): Application
    {
        return $this->application;
    }
}
