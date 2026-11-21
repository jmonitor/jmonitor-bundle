<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;

/**
 * Tells whether the cache keeps what is written to it from one display to the next.
 */
final readonly class CacheProbe
{
    private const MARK = 'jmonitor.stack.mark';
    private const MARK_SECONDS = 86400;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {}

    public function state(): CacheState
    {
        $pool = $this->cache;

        while ($pool instanceof TraceableAdapter) {
            $pool = $pool->getPool();
        }

        if ($pool instanceof ArrayAdapter || $pool instanceof NullAdapter) {
            return CacheState::OutOfOrder;
        }

        try {
            $mark = $this->cache->getItem(self::MARK);

            if ($mark->isHit()) {
                return CacheState::Working;
            }

            $this->cache->save($mark->set($this->clock->now()->getTimestamp())->expiresAfter(self::MARK_SECONDS));

            return $this->cache->getItem(self::MARK)->isHit() ? CacheState::Cold : CacheState::OutOfOrder;
        } catch (\Throwable) {
            return CacheState::OutOfOrder;
        }
    }
}
