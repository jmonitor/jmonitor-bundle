<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\ResetInterface;

/**
 * Runs one collector the way the collector library does: boot, collect, drop nulls, reset.
 * Exceptions are left to the caller.
 */
final readonly class CollectorRunner
{
    /**
     * @return array<string, mixed>
     *
     * @throws \Throwable
     */
    public function run(CollectorInterface $collector): array
    {
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
