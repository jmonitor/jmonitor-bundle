<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Works until $breaks says otherwise for a call: a backend that fails in the middle of a read.
 */
final class BreakingCachePool implements CacheItemPoolInterface
{
    private int $calls = 0;

    /**
     * @param \Closure(string $method, int $call): bool $breaks $call counts from 1, over every method
     */
    public function __construct(
        private readonly \Closure $breaks,
        public readonly ArrayAdapter $inner = new ArrayAdapter(),
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
        if (($this->breaks)($method, ++$this->calls)) {
            throw new \RuntimeException('Connection lost');
        }
    }
}
