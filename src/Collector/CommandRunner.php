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
 * Run console commands and return output
 */
class CommandRunner
{
    private Application $application;

    public function __construct(KernelInterface $kernel)
    {
        $this->application = new Application(clone $kernel);
        $this->application->setAutoExit(false);
    }

    public function run(string $command, array $input = []): ?string
    {
        if (!$this->application->has($command)) {
            return null;
        }

        $input = array_merge(['command' => $command], $input);

        $output = new BufferedOutput();
        $this->application->run(new ArrayInput($input), $output);

        $output = $output->fetch();

        return $output === '' ? null : $output;
    }

    public function getApplication(): Application
    {
        return $this->application;
    }
}
