<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Reads, but reports its writes as failed without throwing: a full disk, or a Redis that refuses writes.
 * With $maxBytes, it only refuses the values larger than that once serialized, like a Memcached under its item size.
 */
final class RefusingCachePool implements CacheItemPoolInterface
{
    private bool $deferredRefused = false;

    public function __construct(
        private readonly bool $refusesSave = true,
        public readonly CacheItemPoolInterface $inner = new ArrayAdapter(),
        private readonly ?int $maxBytes = null,
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
        if ($this->maxBytes === null) {
            return !$this->refusesSave && $this->inner->save($item);
        }

        return $this->fits($item) && $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if ($this->maxBytes === null) {
            return false;
        }

        if (!$this->fits($item)) {
            $this->deferredRefused = true;

            return false;
        }

        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        if ($this->maxBytes === null) {
            return false;
        }

        $refused = $this->deferredRefused;
        $this->deferredRefused = false;

        return $this->inner->commit() && !$refused;
    }

    private function fits(CacheItemInterface $item): bool
    {
        return strlen(serialize($item->get())) <= $this->maxBytes;
    }
}
