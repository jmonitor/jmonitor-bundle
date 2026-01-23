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
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after a number of seconds (ignored with --dry-run)');
        $this->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Stop when memory usage exceeds the limit, e.g. 64M (ignored with --dry-run)');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after a number of pushes (ignored with --dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jmonitor = $input->getArgument('collector')
            ? $this->jmonitor->withCollector($input->getArgument('collector'))
            : $this->jmonitor;

        $shouldSend = !$input->getOption('dry-run');
        $timeLimitSeconds = $shouldSend ? $this->getPositiveIntOption($input, 'time-limit') : null;
        $memoryLimitBytes = $shouldSend ? $this->parseMemoryLimit($input->getOption('memory-limit')) : null;
        $pushLimit = $shouldSend ? $this->getPositiveIntOption($input, 'limit') : null;
        $startedAt = $shouldSend ? microtime(true) : 0.0;
        $pushCount = 0;

        do {
            if ($this->shouldStop) {
                break;
            }

            if ($shouldSend && $this->shouldStopForLimits($startedAt, $timeLimitSeconds, $memoryLimitBytes, $pushLimit, $pushCount)) {
                break;
            }

            $result = $jmonitor->collect($shouldSend, false);

            if ($shouldSend) {
                $pushCount++;
            }

            $this->logger->debug('Metrics collected', [
                'metrics' => $result->getMetrics(),
            ]);

            if ($result->getResponse()?->getStatusCode() && $result->getResponse()->getStatusCode() >= 400) {
                if ($result->getResponse()->getStatusCode() === 429) {
                    $this->logger->info('Rate limit reached');
                } else {
                    $this->logger->error('Response error', [
                        'body' => $result->getResponse()->getBody()->getContents(),
                        'code' => $result->getResponse()->getStatusCode(),
                        'headers' => $result->getResponse()->getHeaders(),
                    ]);
                }
            }

            if ($result->getErrors()) {
                $this->logger->error('Errors', [
                    'errors' => $result->getErrors(),
                ]);
            }

            $this->logger->info($result->getConclusion() ?? 'No conclusion');

            if (!$shouldSend || $this->shouldStop) {
                break;
            }

            if ($this->shouldStopForLimits($startedAt, $timeLimitSeconds, $memoryLimitBytes, $pushLimit, $pushCount)) {
                break;
            }

            $retryAfter = $result->getResponse()?->getHeader('x-ratelimit-retry-after')[0] ?? null;

            if ($retryAfter === null) {
                break;
            }
            $retryAfter = (int) $retryAfter;

            if ($retryAfter <= 0) {
                break;
            }

            $sleepSeconds = max(0, $retryAfter);

            // this is not normal
            if ($sleepSeconds <= 0) {
                $this->logger->error('Retry after is not positive, something is wrong.');
                break;
            }

            $this->logger->debug('Next push in ' . $sleepSeconds . ' seconds');

            usleep((int) ($sleepSeconds * 1_000_000));
        } while (true);

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

    private function getPositiveIntOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);

        if ($value === null) {
            return null;
        }

        $intValue = filter_var($value, FILTER_VALIDATE_INT);

        if ($intValue === false || $intValue <= 0) {
            throw new \InvalidArgumentException(sprintf('Option "--%s" must be a positive integer.', $name));
        }

        return $intValue;
    }

    private function parseMemoryLimit(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $bytes = ini_parse_quantity(trim($value));

        if ($bytes <= 0) {
            throw new \InvalidArgumentException('Option "--memory-limit" must be greater than 0.');
        }

        return $bytes;
    }

    private function shouldStopForLimits(
        float $startedAt,
        ?int $timeLimitSeconds,
        ?int $memoryLimitBytes,
        ?int $pushLimit,
        int $pushCount,
    ): bool
    {
        if ($pushLimit !== null && $pushCount >= $pushLimit) {
            $this->logger->info('Push limit reached', ['limit' => $pushLimit]);
            return true;
        }

        if ($timeLimitSeconds !== null) {
            $elapsedSeconds = microtime(true) - $startedAt;

            if ($elapsedSeconds >= $timeLimitSeconds) {
                $this->logger->info('Time limit reached', ['limit' => $timeLimitSeconds]);
                return true;
            }
        }

        if ($memoryLimitBytes !== null) {
            $memoryUsage = memory_get_usage(true);

            if ($memoryUsage >= $memoryLimitBytes) {
                $this->logger->info('Memory limit reached', [
                    'limit' => $memoryLimitBytes,
                    'usage' => $memoryUsage,
                ]);
                return true;
            }
        }

        return false;
    }
}
