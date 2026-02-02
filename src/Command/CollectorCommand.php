<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Command;

use Jmonitor\Jmonitor;
use Jmonitor\CollectionResult;
use Jmonitor\JmonitorBundle\Command\Dto\Limits;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('jmonitor:collect', description: 'Collect and send metrics to Jmonitor')]
class CollectorCommand extends Command
{
    private Jmonitor $jmonitor;
    private LoggerInterface $logger;
    private bool $stopSignalReceived = false;
    private Limits $limits;

    /**
     * Number of times a 5xx error occurred.
     */
    private int $serverErrorCount = 0;

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
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after a number of seconds');
        $this->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Stop when memory usage exceeds the limit, e.g. 64M');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->limits = new Limits(
            timeLimit: $input->getOption('time-limit'),
            memoryLimit: $input->getOption('memory-limit'),
        );

        $jmonitor = $input->getArgument('collector')
            ? $this->jmonitor->withCollector($input->getArgument('collector'))
            : $this->jmonitor;

        do {
            if ($this->shouldStop()) {
                break;
            }

            $result = $jmonitor->collect(!$input->getOption('dry-run'), false);

            $this->logger->debug('Metrics collected', [
                'metrics' => $result->getMetrics(),
            ]);

            if ($result->getErrors()) {
                $this->logger->error('Errors', [
                    'errors' => $result->getErrors(),
                ]);
            }

            if (!$result->getResponse()) {
                $this->logger->info('Collection done.', [
                    'conclusion' => $result->getConclusion(),
                ]);
            }

            if (!$this->handleResult($result)) {
                break;
            }
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
        $this->logger->info('Stop signal received, stopping...', ['signal' => $signal]);

        return false;
    }

    private function shouldStop(): bool
    {
        if ($this->stopSignalReceived) {
            return true;
        }

        if ($this->limits->limitReached()) {
            $this->logger->info('Limits reached');

            return true;
        }

        return false;
    }

    /**
     * Pause execution for a given number of seconds
     * - allowing for signals to be handled each second
     * - checking limits defined by the user each second
     */
    private function sleepInterruptible(int $sleepSeconds): void
    {
        for ($i = 0; $i < abs($sleepSeconds); $i++) {
            if ($this->shouldStop()) {
                break;
            }

            sleep(1);
        }
    }

    /**
     * @return bool does the worker should continue to loop?
     */
    private function handleResult(CollectionResult $result): bool
    {
        $statusCode = $result->getResponse()->getStatusCode();

        if ($statusCode >= 500) {
            $this->handle5xxError();

            return true;
        }

        $this->serverErrorCount = 0;

        if ($statusCode === 429) {
            $this->handle429Error($result->getResponse());

            return true;
        }

        if ($statusCode >= 400) {
            $this->handleOther4xxError($result->getResponse());

            return false;
        }

        $this->logger->info('Collection done.', [
            'conclusion' => $result->getConclusion(),
        ]);

        $this->handle2xx($result->getResponse());

        return true;
    }

    private function handle5xxError(): void
    {
        $delays = [15, 30, 60, 120, 300];
        $sleepSeconds = $delays[$this->serverErrorCount] ?? 300;

        $this->logger->error(sprintf('Server error on Jmonitor side, sorry about that. Retrying in %d seconds', $sleepSeconds));

        $this->sleepInterruptible($sleepSeconds);

        $this->serverErrorCount++;
    }

    private function handle429Error(ResponseInterface $response): void
    {
        $sleepSeconds = (int) ($response->getHeader('x-ratelimit-retry-after')[0] ?? 15);

        $this->logger->info(sprintf('Rate limit reached, waiting for %d seconds', $sleepSeconds));

        $this->sleepInterruptible($sleepSeconds);
    }

    private function handleOther4xxError(ResponseInterface $response): void
    {
        $this->logger->error('Client error, stopping collector', [
            'body' => $response->getBody()->getContents(),
            'code' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
        ]);
    }

    private function handle2xx(ResponseInterface $response): void
    {
        $sleepSeconds = (int) ($response->getHeader('x-ratelimit-retry-after')[0] ?? 15);

        $this->logger->debug('Next push in ' . $sleepSeconds . ' seconds');

        $this->sleepInterruptible($sleepSeconds);
    }
}
