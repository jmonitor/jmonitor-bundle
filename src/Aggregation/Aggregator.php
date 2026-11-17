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
        /** @var array<string, Aggregate> $merged */
        $merged = [];

        foreach ($entries as $entry) {
            if ($entry->type !== Entry::TYPE_REQUEST) {
                continue;
            }

            foreach (Period::cases() as $period) {
                $bucket = $period->bucketFor($entry->startedAt);
                $add = static function (string $type, float $value) use (&$merged, $period, $bucket, $entry): void {
                    $id = $period->value . '|' . $bucket . '|' . $type . '|' . $entry->key;

                    if (!isset($merged[$id])) {
                        $merged[$id] = new Aggregate($period, $bucket, $type, $entry->key, 1, $value, $value);

                        return;
                    }

                    ++$merged[$id]->count;
                    $merged[$id]->sum += $value;
                    $merged[$id]->max = max($merged[$id]->max, $value);
                };

                $add(Metric::REQUEST_DURATION, $entry->durationMs);
                $add(Metric::REQUEST_DURATION_LE_PREFIX . Metric::durationBound($entry->durationMs), 1.0);

                $memory = $entry->attributes['memory'] ?? null;
                if (is_numeric($memory) && $memory >= 0) {
                    $add(Metric::REQUEST_MEMORY, (float) $memory);
                }

                $status = $entry->attributes['status'] ?? null;
                if (is_numeric($status) && $status >= 200 && $status <= 599) {
                    $add(Metric::REQUEST_STATUS_PREFIX . intdiv((int) $status, 100) . 'xx', 1.0);
                }

                if ($entry->durationMs >= $this->slowThresholdMs) {
                    $add(Metric::REQUEST_SLOW, 1.0);
                }
            }
        }

        return array_values($merged);
    }
}
