<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Decorator for cacheable collectors.
 */
final class CacheableComponentCollector implements ComponentCollectorInterface
{
    private CacheableComponentCollectorInterface $collector;
    private CacheItemPoolInterface $cache;

    public function __construct(CacheableComponentCollectorInterface $collector, CacheItemPoolInterface $cache)
    {
        $this->collector = $collector;
        $this->cache = $cache;
    }

    public function collect(): array
    {
        $cacheKey = sprintf('jmonitor_collector_%s', str_replace('\\', '_', get_class($this->collector)));
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $data = $this->collector->collect();

        $cacheItem->set($data);
        $cacheItem->expiresAfter($this->collector->getCacheTtl());

        $this->cache->save($cacheItem);

        return $data;
    }
}
