<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Counts the calls that reach the cache backend: saveDeferred() waits for commit().
 */
final class CountingCachePool implements CacheItemPoolInterface
{
    /** @var array<string, int> round trips by method */
    public array $calls = [];

    public function __construct(
        private readonly CacheItemPoolInterface $inner = new ArrayAdapter(),
    ) {}

    public function roundTrips(): int
    {
        return array_sum($this->calls);
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    public function getItem(string $key): CacheItemInterface
    {
        $this->count(__FUNCTION__);

        return $this->inner->getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        $this->count(__FUNCTION__);

        return $this->inner->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        $this->count(__FUNCTION__);

        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        $this->count(__FUNCTION__);

        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        $this->count(__FUNCTION__);

        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->count(__FUNCTION__);

        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        $this->count(__FUNCTION__);

        return $this->inner->commit();
    }

    private function count(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }
}
