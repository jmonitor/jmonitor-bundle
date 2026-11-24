<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use Psr\Log\LoggerInterface;

/**
 * Runs the cache operations of one read or forget: the first one that throws is logged,
 * and the cache is left alone for the rest of it.
 *
 * @internal
 */
final class CacheGuard
{
    private bool $refusalLogged = false;
    private bool $lifts = true;

    /** @var ?\Closure(): float */
    private ?\Closure $clock = null;

    private float $bound = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
        private bool $works,
    ) {}

    public function works(): bool
    {
        return $this->works;
    }

    /**
     * From now on, an operation that takes longer than $seconds leaves the cache alone for the rest, as one that
     * throws: a cache that hangs would keep each of the next ones waiting as long.
     *
     * @param \Closure(): float $clock
     */
    public function bound(\Closure $clock, float $seconds): void
    {
        $this->clock = $clock;
        $this->bound = $seconds;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     * @param T             $fallback
     *
     * @return T
     */
    public function attempt(\Closure $operation, mixed $fallback): mixed
    {
        if (!$this->works) {
            return $fallback;
        }

        $startedAt = $this->clock === null ? null : ($this->clock)();

        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->works = false;
            $this->logger->warning('The cache of the live stack failed, it is left aside: {reason}', [
                'reason' => ErrorMessage::clean(ErrorMessage::of($e)),
                'exception' => $e,
            ]);

            return $fallback;
        } finally {
            $this->timed($startedAt);
        }
    }

    /**
     * Runs an operation whatever happened to the cache since the read began, until one of them throws:
     * the next ones would wait on the same broken backend.
     *
     * @param \Closure(): void $operation
     */
    public function lift(\Closure $operation): void
    {
        if (!$this->lifts) {
            return;
        }

        $startedAt = $this->clock === null ? null : ($this->clock)();

        try {
            $operation();
        } catch (\Throwable) {
            $this->lifts = false;
        } finally {
            $this->timed($startedAt);
        }
    }

    private function timed(?float $startedAt): void
    {
        if ($startedAt !== null && $this->clock !== null && ($this->clock)() - $startedAt > $this->bound) {
            $this->works = false;
            $this->lifts = false;
        }
    }

    /**
     * A write reported as failed without an exception, logged once per read. A refused witness leaves the cache
     * aside as well: without it, every request would collect.
     */
    public function refused(string $what, bool $leaveAside): void
    {
        if (!$this->works) {
            return;
        }

        $this->works = !$leaveAside;

        if (!$this->refusalLogged) {
            $this->refusalLogged = true;
            $this->logger->warning('The cache of the live stack refused to save {what}.', ['what' => $what]);
        }
    }
}
