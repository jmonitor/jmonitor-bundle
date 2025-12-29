<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\Components\CacheableComponentCollector;
use Jmonitor\JmonitorBundle\Collector\Components\CacheableComponentCollectorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class CacheableComponentCollectorTest extends TestCase
{
    public function testCollectUsesCache(): void
    {
        $collector = $this->createMock(CacheableComponentCollectorInterface::class);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cacheItem = $this->createMock(CacheItemInterface::class);

        $collector->method('getCacheTtl')->willReturn(60);
        $collector->expects($this->once())->method('collect')->willReturn(['foo' => 'bar']);

        $cache->method('getItem')->willReturn($cacheItem);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->expects($this->once())->method('set')->with(['foo' => 'bar']);
        $cacheItem->expects($this->once())->method('expiresAfter')->with(60);
        $cache->expects($this->once())->method('save')->with($cacheItem);

        $decorator = new CacheableComponentCollector($collector, $cache);
        $result = $decorator->collect();

        $this->assertEquals(['foo' => 'bar'], $result);
    }

    public function testCollectReturnsCacheIfHit(): void
    {
        $collector = $this->createMock(CacheableComponentCollectorInterface::class);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cacheItem = $this->createMock(CacheItemInterface::class);

        $cache->method('getItem')->willReturn($cacheItem);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(['cached' => 'data']);

        $collector->expects($this->never())->method('collect');

        $decorator = new CacheableComponentCollector($collector, $cache);
        $result = $decorator->collect();

        $this->assertEquals(['cached' => 'data'], $result);
    }
}
