<?php

/*
 * This file is part of the jmonitor/jmonitor-bundle package.
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Command;

use Jmonitor\Jmonitor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('jmonitor:collect', description: 'Collect and send metrics to Jmonitor')]
class CollectorCommand extends Command implements SignalableCommandInterface
{
    private const INTERVAL_SECONDS = 15;

    private Jmonitor $jmonitor;
    private LoggerInterface $logger;
    private bool $shouldStop = false;

    public function __construct(Jmonitor $jmonitor, ?LoggerInterface $logger = null)
    {
        parent::__construct();

        $this->jmonitor = $jmonitor;
        $this->logger = $logger ?? new NullLogger();
    }

    protected function configure(): void
    {
        $this->addArgument('collector', InputArgument::OPTIONAL, 'To run a specific collector');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not send metrics to Jmonitor');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jmonitor = $input->getArgument('collector')
            ? $this->jmonitor->withCollector($input->getArgument('collector'))
            : $this->jmonitor;

        $shouldSend = !$input->getOption('dry-run');

        while (!$this->shouldStop) {
            $startTime = microtime(true);

            $result = $jmonitor->collect($shouldSend, false);

            $this->logger->debug('Metrics collected', [
                'metrics' => $result->getMetrics(),
            ]);

            if ($result->getResponse()?->getStatusCode() && $result->getResponse()->getStatusCode() >= 400) {
                $this->logger->error('Response error', [
                    'body' => $result->getResponse()->getBody()->getContents(),
                    'code' => $result->getResponse()->getStatusCode(),
                    'headers' => $result->getResponse()->getHeaders(),
                ]);
            }

            if ($result->getErrors()) {
                $this->logger->error('Errors', [
                    'errors' => $result->getErrors(),
                ]);
            }

            $this->logger->info($result->getConclusion() ?? 'No conclusion');

            $elapsed = microtime(true) - $startTime;
            $sleepSeconds = max(0, self::INTERVAL_SECONDS - $elapsed);
            if ($sleepSeconds > 0) {
                usleep((int) ($sleepSeconds * 1_000_000));
            }
        }

        $this->logger->info('Collector stopped');

        return Command::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        $signals = [];

        foreach (['SIGINT', 'SIGTERM', 'SIGQUIT'] as $signal) {
            if (defined($signal)) {
                $signals[] = constant($signal);
            }
        }

        return $signals;
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shouldStop = true;
        $this->logger->info('Stop signal received', ['signal' => $signal]);

        return false;
    }
}
