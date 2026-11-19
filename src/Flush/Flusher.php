<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Flush;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class Flusher
{
    private ?\Throwable $lastFailure = null;
    private bool $labelsConfirmed = false;
    private bool $bookkeepingFailureLogged = false;

    public function __construct(
        private readonly BufferInterface $buffer,
        private readonly Aggregator $aggregator,
        private readonly StorageInterface $storage,
        private readonly FlushLock $lock,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $purgeIntervalSeconds = 300,
        private readonly ?HotKeys $hotKeys = null,
        private readonly ?Recorder $recorder = null,
        private readonly ?LabelSync $labels = null,
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
        $this->labelsConfirmed = false;
        $acquired = false;
        $this->recorder?->pause();

        try {
            try {
                $acquired = $this->lock->acquire();
                if (!$acquired) {
                    return false;
                }

                $this->buffer->drain(fn(iterable $entries) => $this->store($entries));
                $this->purgeIfDue();
            } finally {
                if ($acquired && !$this->labelsConfirmed) {
                    $this->confirmLabels([], null);
                }
                $this->hotKeys?->reset();
                $this->lock->release();
            }

            return true;
        } catch (\Throwable $e) {
            $this->lastFailure = $e;
            $this->logger?->error('JMonitor could not flush its buffer: ' . $e->getMessage(), ['exception' => $e]);

            return false;
        } finally {
            $this->recorder?->resume();
        }
    }

    /**
     * @param iterable<Entry> $entries
     */
    private function store(iterable $entries): void
    {
        if ($this->storage->prepare()) {
            $this->forgetStoredLabels();
        }
        $aggregates = $this->aggregator->aggregate($entries);
        $now = $this->clock->now()->getTimestamp();
        $labels = $this->labels?->labelsFor($aggregates, $now) ?? [];
        $this->storage->store($aggregates, $now, $labels);

        $this->confirmLabels($labels, $now);

        try {
            $this->hotKeys?->commit();
        } catch (\Throwable $e) {
            $this->logBookkeepingFailure($e);
        }
    }

    /**
     * @param array<string, ?string> $labels
     */
    private function confirmLabels(array $labels, ?int $now): void
    {
        $this->labelsConfirmed = true;
        if ($this->labels === null) {
            return;
        }

        try {
            $this->labels->confirm($labels, $now ?? $this->clock->now()->getTimestamp());
        } catch (\Throwable $e) {
            $this->logBookkeepingFailure($e);
        }
    }

    private function forgetStoredLabels(): void
    {
        try {
            $this->labels?->forgetStored();
        } catch (\Throwable $e) {
            $this->logBookkeepingFailure($e);
        }
    }

    private function logBookkeepingFailure(\Throwable $e): void
    {
        if ($this->bookkeepingFailureLogged) {
            return;
        }

        $this->bookkeepingFailureLogged = true;
        $this->logger?->error('JMonitor could not update its bookkeeping after a flush: ' . $e->getMessage(), ['exception' => $e]);
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
