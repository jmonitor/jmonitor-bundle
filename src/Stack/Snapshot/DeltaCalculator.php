<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Keeps a reference sample of each collector in the cache and compares each new sample with it.
 * A sample taken less than $minElapsed after the reference is compared with it but does not
 * replace it, so the next one still spans a full interval.
 */
final readonly class DeltaCalculator
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $minElapsed,
        private int $maxElapsed = 300,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function track(string $name, array $data, int $now): ?Delta
    {
        $item = $this->cache->getItem('jmonitor.stack.previous.' . $name);
        $previous = $item->get();
        $current = Delta::flatten($data);

        if (!is_array($previous) || !is_int($previous['timestamp'] ?? null) || !is_array($previous['values'] ?? null)) {
            $this->save($item, $current, $now);

            return null;
        }

        $elapsed = $now - $previous['timestamp'];

        if ($elapsed < 0 || $elapsed >= $this->minElapsed) {
            $this->save($item, $current, $now);
        }

        if ($elapsed < 1 || $elapsed > $this->maxElapsed) {
            return null;
        }

        return new Delta($previous['values'], $current, $elapsed);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function save(CacheItemInterface $item, array $values, int $now): void
    {
        $item->set(['timestamp' => $now, 'values' => $values]);
        $item->expiresAfter($this->maxElapsed * 2);
        $this->cache->save($item);
    }
}
