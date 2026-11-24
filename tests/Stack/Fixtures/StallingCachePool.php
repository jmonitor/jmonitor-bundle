<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Plays a cache that hangs: while $stall is set, every call takes that long on the clock, and is recorded.
 */
final class StallingCachePool implements CacheItemPoolInterface
{
    public float $stall = 0.0;

    /** @var list<string> the methods called while stalling */
    public array $stalled = [];

    public function __construct(
        private readonly CacheItemPoolInterface $inner,
        private readonly MockClock $clock,
    ) {}

    public function getItem(string $key): CacheItemInterface
    {
        $this->enter(__FUNCTION__);

        return $this->inner->getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        $this->enter(__FUNCTION__);

        return $this->inner->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        $this->enter(__FUNCTION__);

        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        $this->enter(__FUNCTION__);

        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        $this->enter(__FUNCTION__);

        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        $this->enter(__FUNCTION__);

        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->enter(__FUNCTION__);

        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->enter(__FUNCTION__);

        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        $this->enter(__FUNCTION__);

        return $this->inner->commit();
    }

    private function enter(string $method): void
    {
        if ($this->stall > 0) {
            $this->stalled[] = $method;
            $this->clock->sleep($this->stall);
        }
    }
}
