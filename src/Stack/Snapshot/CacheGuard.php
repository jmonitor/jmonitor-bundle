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
    public function __construct(
        private readonly LoggerInterface $logger,
        private bool $works,
    ) {}

    public function works(): bool
    {
        return $this->works;
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

        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->works = false;
            $this->logger->error('The cache of the live stack failed, it is left aside: {reason}', [
                'reason' => ErrorMessage::clean(ErrorMessage::of($e)),
                'exception' => $e,
            ]);

            return $fallback;
        }
    }

    /**
     * A write reported as failed without an exception: the cache keeps nothing, it is left aside as well.
     */
    public function refused(string $what): void
    {
        if (!$this->works) {
            return;
        }

        $this->works = false;
        $this->logger->error('The cache of the live stack refused to save {what}, it is left aside.', ['what' => $what]);
    }
}
