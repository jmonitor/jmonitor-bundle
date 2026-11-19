<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

final class Metric
{
    public const REQUEST_DURATION = 'request.duration';
    public const REQUEST_MEMORY = 'request.memory';
    public const REQUEST_SLOW = 'request.slow';
    public const REQUEST_STATUS_PREFIX = 'request.status.';
    public const REQUEST_DURATION_LE_PREFIX = 'request.duration_le.';
    public const REQUEST_SQL_TIME = 'request.sql_time';
    public const REQUEST_SQL_COUNT = 'request.sql_count';
    public const QUERY_DURATION = 'query.duration';
    public const QUERY_SLOW = 'query.slow';
    public const NPLUS1_CALLS = 'nplus1.calls';
    public const NPLUS1_DURATION = 'nplus1.duration';

    /** @var list<int> */
    public const DURATION_BOUNDS_MS = [10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];
    public const DURATION_OVERFLOW = 'inf';

    public static function durationBound(float $durationMs): string
    {
        foreach (self::DURATION_BOUNDS_MS as $bound) {
            if ($durationMs <= $bound) {
                return (string) $bound;
            }
        }

        return self::DURATION_OVERFLOW;
    }
}
