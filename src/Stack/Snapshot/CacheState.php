<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

enum CacheState: string
{
    case Working = 'working';
    /** the cache works, but kept nothing from a previous display */
    case Cold = 'cold';
    /** the cache does not keep what is written to it */
    case OutOfOrder = 'out_of_order';
}
