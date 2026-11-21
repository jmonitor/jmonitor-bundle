<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

/**
 * When cached data was read, and when it is read again.
 */
final readonly class Freshness
{
    public function __construct(
        public int $readAt,
        public int $nextReadAt,
        public int $lifetime,
        public int $now,
    ) {}

    public function age(): int
    {
        return max(0, $this->now - $this->readAt);
    }

    public function nextReadIn(): int
    {
        return max(0, $this->nextReadAt - $this->now);
    }
}
