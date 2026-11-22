<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RecordingCachePool implements CacheItemPoolInterface
{
    /** @var array<string, ?int> ttl requested for each key */
    public array $ttls = [];

    public function __construct(
        private readonly CacheItemPoolInterface $inner = new ArrayAdapter(),
    ) {}

    public function getItem(string $key): CacheItemInterface
    {
        return new RecordingCacheItem($this->inner->getItem($key), $this);
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->inner->save($item instanceof RecordingCacheItem ? $item->inner : $item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item instanceof RecordingCacheItem ? $item->inner : $item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function record(string $key, ?int $ttl): void
    {
        $this->ttls[$key] = $ttl;
    }
}
