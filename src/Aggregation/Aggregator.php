<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

use Jmonitor\JmonitorBundle\Recording\Entry;

final class Aggregator
{
    public function __construct(private readonly int $slowThresholdMs) {}

    /**
     * @param iterable<Entry> $entries
     *
     * @return list<Aggregate>
     */
    public function aggregate(iterable $entries): array
    {
        /** @var array<string, array{minute: int, key: string, counters: array<string, array{int, float, float}>}> $groups */
        $groups = [];

        foreach ($entries as $entry) {
            if ($entry->type !== Entry::TYPE_REQUEST || !is_finite($entry->durationMs)) {
                continue;
            }

            $minute = Period::OneHour->bucketFor($entry->startedAt);
            $group = $minute . '|' . $entry->key;
            $groups[$group] ??= ['minute' => $minute, 'key' => $entry->key, 'counters' => []];
            $counters = &$groups[$group]['counters'];

            self::add($counters, Metric::REQUEST_DURATION, $entry->durationMs);
            self::add($counters, Metric::REQUEST_DURATION_LE_PREFIX . Metric::durationBound($entry->durationMs), 1.0);

            $memory = $entry->attributes['memory'] ?? null;
            if (is_numeric($memory) && $memory >= 0 && is_finite((float) $memory)) {
                self::add($counters, Metric::REQUEST_MEMORY, (float) $memory);
            }

            $status = $entry->attributes['status'] ?? null;
            if (is_numeric($status) && $status >= 200 && $status <= 599) {
                self::add($counters, Metric::REQUEST_STATUS_PREFIX . intdiv((int) $status, 100) . 'xx', 1.0);
            }

            if ($entry->durationMs >= $this->slowThresholdMs) {
                self::add($counters, Metric::REQUEST_SLOW, 1.0);
            }

            unset($counters);
        }

        /** @var array<string, Aggregate> $merged */
        $merged = [];

        foreach ($groups as ['minute' => $minute, 'key' => $key, 'counters' => $counters]) {
            foreach (Period::cases() as $period) {
                $bucket = $period->bucketFor($minute);

                foreach ($counters as $type => [$count, $sum, $max]) {
                    $id = $period->value . '|' . $bucket . '|' . $type . '|' . $key;

                    if (!isset($merged[$id])) {
                        $merged[$id] = new Aggregate($period, $bucket, (string) $type, $key, $count, $sum, $max);

                        continue;
                    }

                    $merged[$id]->count += $count;
                    $merged[$id]->sum += $sum;
                    $merged[$id]->max = max($merged[$id]->max, $max);
                }
            }
        }

        return array_values($merged);
    }

    /**
     * @param array<string, array{int, float, float}> $counters
     */
    private static function add(array &$counters, string $type, float $value): void
    {
        if (!isset($counters[$type])) {
            $counters[$type] = [1, $value, $value];

            return;
        }

        ++$counters[$type][0];
        $counters[$type][1] += $value;
        if ($value > $counters[$type][2]) {
            $counters[$type][2] = $value;
        }
    }
}
