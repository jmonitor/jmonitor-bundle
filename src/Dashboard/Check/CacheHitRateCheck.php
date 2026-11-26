<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

final class CacheHitRateCheck implements KeyedCheckInterface
{
    public const MIN_READS = 20;

    public function id(): string
    {
        return 'cache.low_hit_rate';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function screen(): string
    {
        return 'cache-http';
    }

    public function screenLabel(): string
    {
        return 'Cache & HTTP';
    }

    public function passed(): string
    {
        return 'no pool with a low hit rate';
    }

    public function metrics(): array
    {
        return [Metric::CACHE_HIT, Metric::CACHE_MISS];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Cache);
    }

    public static function isLow(int $hits, int $reads): bool
    {
        return $reads >= self::MIN_READS && $hits * 2 < $reads;
    }

    public function raisedBy(array $keys): bool
    {
        $hits = $keys[Metric::CACHE_HIT] ?? [];
        $misses = $keys[Metric::CACHE_MISS] ?? [];

        foreach (array_keys($hits + $misses) as $hash) {
            $found = isset($hits[$hash]) ? $hits[$hash]->count : 0;

            if (self::isLow($found, $found + (isset($misses[$hash]) ? $misses[$hash]->count : 0))) {
                return true;
            }
        }

        return false;
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $low = array_values(array_filter($report->rows, static fn(ReportRow $row): bool => self::isLow($row->count(Metric::CACHE_HIT), self::reads($row))));
        usort($low, static fn(ReportRow $a, ReportRow $b): int => $a->count(Metric::CACHE_HIT) * self::reads($b) <=> $b->count(Metric::CACHE_HIT) * self::reads($a)
            ?: self::reads($b) <=> self::reads($a)
            ?: strcmp($a->key, $b->key));
        $culprit = $low[0] ?? null;
        $count = \count($low);

        if ($culprit === null) {
            $sentence = 'Pools that found less than half of ' . self::MIN_READS . ' reads or more in the ' . strtolower(Format::period($period)) . '.';
        } else {
            $reads = self::reads($culprit);
            $sentence = sprintf('%s found %s of its %s reads.', $culprit->key, Format::percent($culprit->count(Metric::CACHE_HIT) / $reads), Format::number($reads));

            if ($count > 1) {
                $sentence .= ' ' . Culprit::others($count - 1, 'other pool is', 'other pools are') . ' under 50 % too.';
            }
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            match ($count) {
                0 => 'Low cache hit rate',
                1 => '1 pool with a low hit rate',
                default => Format::number($count) . ' pools with a low hit rate',
            },
            $sentence,
            'A miss means the work was done again, then written to the cache. Under 50 %, a pool costs more than it saves.',
            'Look at how its keys are built and how long its items live: a key that changes on every request never hits.',
            $this->screen(),
        );
    }

    private static function reads(ReportRow $row): int
    {
        return $row->count(Metric::CACHE_HIT) + $row->count(Metric::CACHE_MISS);
    }
}
