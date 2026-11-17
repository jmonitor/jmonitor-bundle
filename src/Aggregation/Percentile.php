<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

final class Percentile
{
    /**
     * @param array<string, int> $countsByBound
     */
    public static function estimate(array $countsByBound, float $quantile, float $max): float
    {
        $total = array_sum($countsByBound);
        if ($total <= 0) {
            return 0.0;
        }

        $rank = $quantile * $total;
        $cumulative = 0;
        $lower = 0.0;

        foreach ([...Metric::DURATION_BOUNDS_MS, Metric::DURATION_OVERFLOW] as $bound) {
            $count = $countsByBound[(string) $bound] ?? 0;
            $upper = $bound === Metric::DURATION_OVERFLOW ? $max : (float) $bound;

            if ($count > 0 && $cumulative + $count >= $rank) {
                if ($bound === Metric::DURATION_OVERFLOW) {
                    return $max;
                }

                $value = $lower + ($rank - $cumulative) / $count * ($upper - $lower);

                return min($max, max(0.0, $value));
            }

            $cumulative += $count;
            $lower = $upper;
        }

        return $max;
    }
}
