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
use Jmonitor\JmonitorBundle\Command\Dto\Limits;
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
    private Jmonitor $jmonitor;
    private LoggerInterface $logger;
    private bool $stopSignalReceived = false;

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
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after a number of seconds (ignored with --dry-run)');
        $this->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Stop when memory usage exceeds the limit, e.g. 64M (ignored with --dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limits = new Limits(
            timeLimit: $input->getOption('time-limit'),
            memoryLimit: $input->getOption('memory-limit'),
        );

        $jmonitor = $input->getArgument('collector')
            ? $this->jmonitor->withCollector($input->getArgument('collector'))
            : $this->jmonitor;

        do {
            if ($this->stopSignalReceived) {
                break;
            }

            if ($limits->limitReached()) {
                $this->logger->info('Limits reached');
                break;
            }

            $result = $jmonitor->collect(!$input->getOption('dry-run'), false);

            $this->logger->debug('Metrics collected', [
                'metrics' => $result->getMetrics(),
            ]);

            switch (true) {
                case $result->getResponse()?->getStatusCode() === 429:
                    $this->logger->info('Rate limit reached');
                    break;
                case $result->getResponse()?->getStatusCode() >= 400:
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

            if (!$result->getResponse()) {
                break;
            }

            // @phpstan-ignore-next-line
            if ($limits->limitReached()) {
                break;
            }

            $sleepSeconds = (int) ($result->getResponse()->getHeader('x-ratelimit-retry-after')[0] ?? 0);

            if ($sleepSeconds <= 0) {
                // this is not normal
                break;
            }

            $this->logger->debug('Next push in ' . $sleepSeconds . ' seconds');

            sleep($sleepSeconds);
        } while (true);

        $this->logger->info('Collector stopped');

        return Command::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        $signals = [];

        foreach (['SIGINT', 'SIGTERM', 'SIGQUIT'] as $signal) {
            defined($signal) && $signals[] = constant($signal);
        }

        return $signals;
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopSignalReceived = true;
        $this->logger->info('Stop signal received', ['signal' => $signal]);

        return false;
    }
}
