<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

interface CacheableComponentCollectorInterface extends ComponentCollectorInterface
{
    /**
     * Returns the cache TTL in seconds.
     */
    public function getCacheTtl(): int;
}
