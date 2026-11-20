<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

use Jmonitor\JmonitorBundle\Recording\Entry;

final class Aggregator
{
    public const OTHER = '(other)';

    private const MAX_OCCURRENCES = 1e9;
    private const MAX_WEIGHT = 1e6;

    public function __construct(
        private readonly int $slowThresholdMs,
        private readonly ?HotKeys $hotKeys = null,
    ) {}

    /**
     * @param iterable<Entry> $entries
     *
     * @return list<Aggregate>
     */
    public function aggregate(iterable $entries): array
    {
        /** @var array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups */
        $groups = [];

        foreach ($entries as $entry) {
            if (!is_finite($entry->durationMs)) {
                continue;
            }

            match ($entry->type) {
                Entry::TYPE_REQUEST => $this->addRequest($groups, Period::OneHour->bucketFor($entry->startedAt), $entry),
                default => null,
            };
        }

        $groups = $this->capKeys($groups);

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

                    $merged[$id]->count = self::saturatingSum($merged[$id]->count, $count);
                    $merged[$id]->sum += $sum;
                    $merged[$id]->max = max($merged[$id]->max, $max);
                }
            }
        }

        return array_values($merged);
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addRequest(array &$groups, int $minute, Entry $entry): void
    {
        $counters = &self::counters($groups, $minute, Family::Request, $entry->key);

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

        $sqlCount = self::number($entry->attributes['sql_n'] ?? null);
        $sqlTime = self::number($entry->attributes['sql_ms'] ?? null);
        if ($sqlCount !== null && $sqlTime !== null && $sqlCount > 0) {
            self::add($counters, Metric::REQUEST_SQL_TIME, $sqlTime);
            self::add($counters, Metric::REQUEST_SQL_COUNT, $sqlCount);
        }

        unset($counters);

        $this->addChildren($groups, $minute, $entry);
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addChildren(array &$groups, int $minute, Entry $unit): void
    {
        $weight = self::number($unit->attributes['cw'] ?? null);
        $weight = $weight !== null && $weight >= 1 && $weight <= self::MAX_WEIGHT ? $weight : 1.0;

        foreach ($unit->children as $child) {
            $occurrences = self::number($child->attributes['n'] ?? null);
            if ($occurrences === null || $occurrences < 0 || $occurrences > self::MAX_OCCURRENCES || !is_finite($child->durationMs)) {
                continue;
            }

            match ($child->type) {
                Entry::TYPE_QUERY => $this->addQuery($groups, $minute, $child, $occurrences, $weight),
                Entry::TYPE_NPLUS1 => $this->addNPlusOne($groups, $minute, $unit, $child, $occurrences, $weight),
                default => null,
            };
        }
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addQuery(array &$groups, int $minute, Entry $child, float $occurrences, float $weight): void
    {
        $max = self::number($child->attributes['m'] ?? null);
        $slow = self::number($child->attributes['sl'] ?? null) ?? 0.0;
        if ($max === null || $slow < 0 || $slow > self::MAX_OCCURRENCES) {
            return;
        }

        $counters = &self::counters($groups, $minute, Family::Query, $child->key);
        self::add($counters, Metric::QUERY_DURATION, $child->durationMs * $weight, $max, (int) round($occurrences * $weight));
        if ($slow > 0) {
            self::add($counters, Metric::QUERY_SLOW, 0.0, 0.0, (int) round($slow * $weight));
        }
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addNPlusOne(array &$groups, int $minute, Entry $unit, Entry $child, float $occurrences, float $weight): void
    {
        $key = (new EntryPointKey($unit->type, $unit->key, $child->key))->toString();
        $counters = &self::counters($groups, $minute, Family::NPlusOne, $key);
        $count = (int) round($weight);
        self::add($counters, Metric::NPLUS1_CALLS, $occurrences * $weight, $occurrences, $count);
        self::add($counters, Metric::NPLUS1_DURATION, $child->durationMs * $weight, $child->durationMs, $count);
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     *
     * @return array<string, array{int, float, float}>
     */
    private static function &counters(array &$groups, int $minute, Family $family, string $key): array
    {
        $id = $minute . '|' . $family->value . '|' . $key;
        $groups[$id] ??= ['minute' => $minute, 'family' => $family, 'key' => $key, 'counters' => []];

        return $groups[$id]['counters'];
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     *
     * @return array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}>
     */
    private function capKeys(array $groups): array
    {
        $weights = [];
        foreach ($groups as ['family' => $family, 'key' => $key, 'counters' => $counters]) {
            $measure = $counters[$family->weightMetric()] ?? null;
            $weights[$family->value][$key] = ($weights[$family->value][$key] ?? 0.0) + ($measure === null ? 0.0 : ($family->isOpen() ? $measure[1] : $measure[0]));
        }

        $folded = [];
        foreach (Family::cases() as $family) {
            $keys = $weights[$family->value] ?? [];
            if ($this->hotKeys !== null && $family->isOpen()) {
                $dropped = array_diff_key($keys, $this->hotKeys->select($family, $keys, $family->cap()));
                if ($dropped !== []) {
                    $folded[$family->value] = $dropped;
                }

                continue;
            }

            if (\count($keys) <= $family->cap()) {
                continue;
            }

            uksort($keys, static fn(int|string $a, int|string $b): int => $keys[$b] <=> $keys[$a] ?: strcmp((string) $a, (string) $b));
            $folded[$family->value] = array_flip(\array_slice(array_map('strval', array_keys($keys)), $family->cap()));
        }

        if ($folded === []) {
            return $groups;
        }

        $result = [];
        foreach ($groups as ['minute' => $minute, 'family' => $family, 'key' => $key, 'counters' => $counters]) {
            if (isset($folded[$family->value][$key])) {
                $key = self::OTHER;
            }

            $id = $minute . '|' . $family->value . '|' . $key;
            if (!isset($result[$id])) {
                $result[$id] = ['minute' => $minute, 'family' => $family, 'key' => $key, 'counters' => $counters];

                continue;
            }

            foreach ($counters as $type => [$count, $sum, $max]) {
                if (!isset($result[$id]['counters'][$type])) {
                    $result[$id]['counters'][$type] = [$count, $sum, $max];

                    continue;
                }

                $result[$id]['counters'][$type][0] = self::saturatingSum($result[$id]['counters'][$type][0], $count);
                $result[$id]['counters'][$type][1] += $sum;
                $result[$id]['counters'][$type][2] = max($result[$id]['counters'][$type][2], $max);
            }
        }

        return $result;
    }

    private static function saturatingSum(int $a, int $b): int
    {
        return $a > \PHP_INT_MAX - $b ? \PHP_INT_MAX : $a + $b;
    }

    private static function number(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) ? (is_finite((float) $value) ? (float) $value : null) : null;
    }

    /**
     * @param array<string, array{int, float, float}> $counters
     */
    private static function add(array &$counters, string $type, float $sum, ?float $max = null, int $count = 1): void
    {
        $max ??= $sum;

        if (!isset($counters[$type])) {
            $counters[$type] = [$count, $sum, $max];

            return;
        }

        $counters[$type][0] = self::saturatingSum($counters[$type][0], $count);
        $counters[$type][1] += $sum;
        if ($max > $counters[$type][2]) {
            $counters[$type][2] = $max;
        }
    }
}
