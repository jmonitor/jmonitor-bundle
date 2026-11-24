<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Plays a request that PHP stopped: once frozen, what the request would still do never reaches the cache.
 */
final class FreezableCachePool implements CacheItemPoolInterface
{
    public bool $frozen = false;

    public function __construct(
        private readonly CacheItemPoolInterface $inner,
    ) {}

    public function getItem(string $key): CacheItemInterface
    {
        return $this->inner->getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        return $this->inner->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->frozen || $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->frozen || $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->frozen || $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->frozen || $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->frozen || $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->frozen || $this->inner->commit();
    }
}
