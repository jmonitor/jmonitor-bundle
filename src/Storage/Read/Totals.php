<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Read;

final class Totals
{
    /**
     * @param array<string, MetricValue>                $types metric type => total over every key in the period
     * @param array<string, array<string, MetricValue>> $keys  metric type => key hash => total, for the types asked key by key, the `(other)` key left out
     */
    public function __construct(public readonly array $types = [], public readonly array $keys = []) {}
}
