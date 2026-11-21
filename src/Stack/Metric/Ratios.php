<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Metric;

use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;

/**
 * Ratios of counters, from a recent delta when there is one, else cumulated since the server started
 * (or since $since, for counters that do not start with the server).
 */
final class Ratios
{
    public static function share(?Delta $delta, string $partKey, string $otherKey, int|float|null $part, int|float|null $other, string $since = Rate::SINCE_SERVER_START): Rate
    {
        if ($delta !== null) {
            $p = $delta->getValue($partKey);
            $o = $delta->getValue($otherKey);

            if ($p !== null && $o !== null && $p + $o > 0) {
                return new Rate(round($p / ($p + $o) * 100, 2), RateSource::Delta, '%', $p, $p + $o);
            }
        }

        if ($part !== null && $other !== null && $part + $other > 0) {
            return new Rate(round($part / ($part + $other) * 100, 2), RateSource::SinceStart, '%', $part, $part + $other, $since);
        }

        return Rate::unavailable('%');
    }

    public static function percent(?Delta $delta, string $partKey, string $totalKey, int|float|null $part, int|float|null $total, string $since = Rate::SINCE_SERVER_START): Rate
    {
        if ($delta !== null) {
            $p = $delta->getValue($partKey);
            $t = $delta->getValue($totalKey);

            if ($p !== null && $t !== null && $t > 0) {
                return new Rate(round($p / $t * 100, 2), RateSource::Delta, '%', $p, $t);
            }
        }

        if ($part !== null && $total !== null && $total > 0) {
            return new Rate(round($part / $total * 100, 2), RateSource::SinceStart, '%', $part, $total, $since);
        }

        return Rate::unavailable('%');
    }

    public static function complement(?Delta $delta, string $partKey, string $totalKey, int|float|null $part, int|float|null $total, string $since = Rate::SINCE_SERVER_START): Rate
    {
        if ($delta !== null) {
            $p = $delta->getValue($partKey);
            $t = $delta->getValue($totalKey);

            if ($p !== null && $t !== null && $t > 0) {
                return new Rate(round(($t - $p) / $t * 100, 2), RateSource::Delta, '%', $p, $t);
            }
        }

        if ($part !== null && $total !== null && $total > 0) {
            return new Rate(round(($total - $part) / $total * 100, 2), RateSource::SinceStart, '%', $part, $total, $since);
        }

        return Rate::unavailable('%');
    }

    /**
     * Mean of a cumulated quantity per event: a total time over a request count, for instance.
     */
    public static function average(?Delta $delta, string $sumKey, string $countKey, int|float|null $sum, int|float|null $count, string $unit, string $since = Rate::SINCE_SERVER_START): Rate
    {
        if ($delta !== null) {
            $s = $delta->getValue($sumKey);
            $c = $delta->getValue($countKey);

            if ($s !== null && $c !== null && $c > 0) {
                return new Rate(round($s / $c, 6), RateSource::Delta, $unit, $s, $c);
            }
        }

        if ($sum !== null && $count !== null && $count > 0) {
            return new Rate(round($sum / $count, 6), RateSource::SinceStart, $unit, $sum, $count, $since);
        }

        return Rate::unavailable($unit);
    }

    /**
     * $current is the counter in the latest sample: when there is one, a missing delta only waits for the next sample.
     */
    public static function perSecond(?Delta $delta, string $key, string $unit = '/s', int|float|null $current = null, string $expected = Rate::AT_NEXT_REFRESH): Rate
    {
        if ($delta === null && $current !== null) {
            return Rate::pending($unit, $expected);
        }

        $value = $delta?->getPerSec($key);

        return $value === null ? Rate::unavailable($unit) : new Rate($value, RateSource::Delta, $unit);
    }
}
