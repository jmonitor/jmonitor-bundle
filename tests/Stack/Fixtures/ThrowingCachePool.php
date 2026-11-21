<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * A backend that is down: every call fails.
 */
final class ThrowingCachePool implements CacheItemPoolInterface
{
    public int $calls = 0;

    public function getItem(string $key): CacheItemInterface
    {
        throw $this->failure();
    }

    public function getItems(array $keys = []): iterable
    {
        throw $this->failure();
    }

    public function hasItem(string $key): bool
    {
        throw $this->failure();
    }

    public function clear(): bool
    {
        throw $this->failure();
    }

    public function deleteItem(string $key): bool
    {
        throw $this->failure();
    }

    public function deleteItems(array $keys): bool
    {
        throw $this->failure();
    }

    public function save(CacheItemInterface $item): bool
    {
        throw $this->failure();
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        throw $this->failure();
    }

    public function commit(): bool
    {
        throw $this->failure();
    }

    private function failure(): \RuntimeException
    {
        ++$this->calls;

        return new \RuntimeException('Connection refused');
    }
}
