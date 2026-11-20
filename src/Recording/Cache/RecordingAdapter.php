<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Cache;

use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Deriving from TraceableAdapter gives the wrapper the interfaces Symfony gives every pool in debug mode, on each
 * Symfony version; every method that would trace a call is overridden to keep none.
 */
class RecordingAdapter extends TraceableAdapter
{
    private const HIT = ['h' => 1];
    private const MISS = ['mi' => 1];
    private const COMPUTED = ['mi' => 1, 'w' => 1];
    private const WRITE = ['w' => 1];
    private const DELETE = ['de' => 1];

    public function __construct(
        AdapterInterface $pool,
        private readonly Recorder $recorder,
        private readonly string $name,
    ) {
        parent::__construct($pool);
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        if (!$this->pool instanceof CacheInterface) {
            throw new \BadMethodCallException(sprintf('Cannot call "%s::get()": this class doesn\'t implement "%s".', get_debug_type($this->pool), CacheInterface::class));
        }

        $unit = $this->recorder->current();
        if ($unit === null) {
            return $this->pool->get($key, $callback, $beta, $metadata);
        }

        $computed = false;
        $saved = false;
        try {
            $value = $this->pool->get($key, static function (CacheItemInterface $item, bool &$save) use ($callback, &$computed, &$saved): mixed {
                $computed = true;
                $value = $callback($item, $save);
                $saved = $save;

                return $value;
            }, $beta, $metadata);
        } catch (\Throwable $e) {
            if ($computed) {
                $this->recorder->add($unit, Entry::TYPE_CACHE, $this->name, 0.0, self::MISS);
            }

            throw $e;
        }

        $this->recorder->add($unit, Entry::TYPE_CACHE, $this->name, 0.0, match (true) {
            !$computed => self::HIT,
            $saved => self::COMPUTED,
            default => self::MISS,
        });

        return $value;
    }

    public function getItem(mixed $key): CacheItem
    {
        $item = $this->pool->getItem($key);

        $unit = $this->recorder->current();
        if ($unit !== null) {
            $this->recorder->add($unit, Entry::TYPE_CACHE, $this->name, 0.0, $item->isHit() ? self::HIT : self::MISS);
        }

        return $item;
    }

    /**
     * An item is counted when the application takes it from the iterable, which stays as lazy as the pool's; an array
     * is counted at once and handed back as is.
     */
    public function getItems(array $keys = []): iterable
    {
        $items = $this->pool->getItems($keys);

        $unit = $this->recorder->current();
        if ($unit === null) {
            return $items;
        }
        if (!\is_array($items)) {
            return $this->counted($unit, $items);
        }

        foreach ($items as $item) {
            $this->recorder->add($unit, Entry::TYPE_CACHE, $this->name, 0.0, $item->isHit() ? self::HIT : self::MISS);
        }

        return $items;
    }

    public function hasItem(mixed $key): bool
    {
        return $this->pool->hasItem($key);
    }

    public function save(CacheItemInterface $item): bool
    {
        $saved = $this->pool->save($item);
        $this->count(self::WRITE);

        return $saved;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $saved = $this->pool->saveDeferred($item);
        $this->count(self::WRITE);

        return $saved;
    }

    public function deleteItem(mixed $key): bool
    {
        $deleted = $this->pool->deleteItem($key);
        $this->count(self::DELETE);

        return $deleted;
    }

    public function deleteItems(array $keys): bool
    {
        $deleted = $this->pool->deleteItems($keys);
        if ($keys !== []) {
            $this->count(['de' => \count($keys)]);
        }

        return $deleted;
    }

    public function delete(string $key): bool
    {
        $deleted = $this->pool->deleteItem($key);
        $this->count(self::DELETE);

        return $deleted;
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->pool->clear($prefix);
    }

    public function commit(): bool
    {
        return $this->pool->commit();
    }

    public function prune(): bool
    {
        return $this->pool instanceof PruneableInterface && $this->pool->prune();
    }

    /**
     * @param array<string, int> $counters
     */
    private function count(array $counters): void
    {
        $this->recorder->add($this->recorder->current(), Entry::TYPE_CACHE, $this->name, 0.0, $counters);
    }

    /**
     * @param iterable<string, CacheItem> $items
     *
     * @return \Generator<string, CacheItem>
     */
    private function counted(int $unit, iterable $items): \Generator
    {
        foreach ($items as $key => $item) {
            $this->recorder->add($unit, Entry::TYPE_CACHE, $this->name, 0.0, $item->isHit() ? self::HIT : self::MISS);

            yield $key => $item;
        }
    }
}
