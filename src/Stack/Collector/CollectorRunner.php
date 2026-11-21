<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\ResetInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs one collector the way the collector library does: boot, collect, drop nulls, reset.
 * Exceptions are left to the caller.
 */
final readonly class CollectorRunner
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Throwable
     */
    public function run(CollectorInterface $collector): array
    {
        if ($collector instanceof LoggerAwareInterface) {
            $collector->setLogger($this->logger);
        }

        try {
            if ($collector instanceof BootableCollectorInterface) {
                $collector->boot();
            }

            return array_filter($collector->collect(), static fn(mixed $value): bool => $value !== null);
        } finally {
            if ($collector instanceof ResetInterface) {
                $collector->reset();
            }
        }
    }
}
