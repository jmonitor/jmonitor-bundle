<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Flush;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class Flusher
{
    private ?\Throwable $lastFailure = null;

    public function __construct(
        private readonly BufferInterface $buffer,
        private readonly Aggregator $aggregator,
        private readonly StorageInterface $storage,
        private readonly FlushLock $lock,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $purgeIntervalSeconds = 300,
        private readonly ?HotKeys $hotKeys = null,
    ) {}

    public function flushIfDue(): bool
    {
        if (!$this->lock->isDue()) {
            return false;
        }

        return $this->run();
    }

    public function flush(): bool
    {
        return $this->run();
    }

    public function lastFailure(): ?\Throwable
    {
        return $this->lastFailure;
    }

    private function run(): bool
    {
        $this->lastFailure = null;

        try {
            try {
                if (!$this->lock->acquire()) {
                    return false;
                }

                $this->buffer->drain(fn(iterable $entries) => $this->store($entries));
                $this->purgeIfDue();
            } finally {
                $this->hotKeys?->reset();
                $this->lock->release();
            }

            return true;
        } catch (\Throwable $e) {
            $this->lastFailure = $e;
            $this->logger?->error('JMonitor could not flush its buffer: ' . $e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    /**
     * @param iterable<Entry> $entries
     */
    private function store(iterable $entries): void
    {
        $this->storage->prepare();
        $this->storage->store($this->aggregator->aggregate($entries), $this->clock->now()->getTimestamp());
        $this->hotKeys?->commit();
    }

    private function purgeIfDue(): void
    {
        if (!$this->lock->isPurgeDue($this->purgeIntervalSeconds)) {
            return;
        }

        $this->storage->purge($this->clock->now()->getTimestamp());
        $this->lock->markPurged();
    }
}
