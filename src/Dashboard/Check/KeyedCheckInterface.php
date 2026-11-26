<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;

/** A check judged key by key instead of by a total. */
interface KeyedCheckInterface extends CheckInterface
{
    /** @param array<string, array<string, MetricValue>> $keys metric type => key hash => total, for metrics() */
    public function raisedBy(array $keys): bool;
}
