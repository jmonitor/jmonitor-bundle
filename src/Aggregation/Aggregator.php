<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

use Jmonitor\JmonitorBundle\Recording\Entry;

final class Aggregator
{
    public const OTHER = '(other)';

    private const MAX_OCCURRENCES = 1e9;
    private const MAX_WEIGHT = 1e6;
    private const CACHE_COUNTERS = ['h' => Metric::CACHE_HIT, 'mi' => Metric::CACHE_MISS, 'w' => Metric::CACHE_WRITE, 'de' => Metric::CACHE_DELETE];

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

            $minute = Period::OneHour->bucketFor($entry->startedAt);
            match ($entry->type) {
                Entry::TYPE_REQUEST => $this->addRequest($groups, $minute, $entry),
                Entry::TYPE_COMMAND => $this->addCommand($groups, $minute, $entry),
                Entry::TYPE_MESSAGE => $this->addMessage($groups, $minute, $entry),
                Entry::TYPE_TASK => $this->addTask($groups, $minute, $entry),
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
        self::addMemory($counters, Metric::REQUEST_MEMORY, $entry);

        $status = $entry->attributes['status'] ?? null;
        if (is_numeric($status) && $status >= 200 && $status <= 599) {
            self::add($counters, Metric::REQUEST_STATUS_PREFIX . intdiv((int) $status, 100) . 'xx', 1.0);
        }

        if ($entry->durationMs >= $this->slowThresholdMs) {
            self::add($counters, Metric::REQUEST_SLOW, 1.0);
        }

        self::addTimeSplit($counters, $entry, Metric::REQUEST_SQL_TIME, Metric::REQUEST_SQL_COUNT, Metric::REQUEST_HTTP_TIME);

        unset($counters);

        $this->addChildren($groups, $minute, $entry);
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addCommand(array &$groups, int $minute, Entry $entry): void
    {
        $counters = &self::counters($groups, $minute, Family::Command, $entry->key);

        self::add($counters, Metric::COMMAND_DURATION, $entry->durationMs);
        self::add($counters, Metric::COMMAND_LAST_RUN, 0.0, $entry->startedAt);
        self::addMemory($counters, Metric::COMMAND_MEMORY, $entry);

        $exit = $entry->attributes['exit'] ?? null;
        if (\is_int($exit) && $exit !== 0) {
            self::add($counters, Metric::COMMAND_FAILED, 1.0);
        }

        self::addTimeSplit($counters, $entry, Metric::COMMAND_SQL_TIME, Metric::COMMAND_SQL_COUNT, Metric::COMMAND_HTTP_TIME);

        unset($counters);

        $this->addChildren($groups, $minute, $entry);
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addMessage(array &$groups, int $minute, Entry $entry): void
    {
        $counters = &self::counters($groups, $minute, Family::Message, $entry->key);

        self::add($counters, Metric::MESSAGE_DURATION, $entry->durationMs);
        self::add($counters, Metric::MESSAGE_DURATION_LE_PREFIX . Metric::durationBound($entry->durationMs), 1.0);
        self::addMemory($counters, Metric::MESSAGE_MEMORY, $entry);

        if (($entry->attributes['failed'] ?? null) === 1) {
            self::add($counters, Metric::MESSAGE_FAILED, 1.0);
        }

        if (($entry->attributes['retried'] ?? null) === 1) {
            self::add($counters, Metric::MESSAGE_RETRIED, 1.0);
        }

        self::addTimeSplit($counters, $entry, Metric::MESSAGE_SQL_TIME, Metric::MESSAGE_SQL_COUNT, Metric::MESSAGE_HTTP_TIME);

        unset($counters);

        $this->addChildren($groups, $minute, $entry);
    }

    /**
     * The maximum of a failure is the start of its run, which makes it the time of the last failure.
     *
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addTask(array &$groups, int $minute, Entry $entry): void
    {
        $counters = &self::counters($groups, $minute, Family::Task, $entry->key);

        self::add($counters, Metric::TASK_DURATION, $entry->durationMs);
        self::add($counters, Metric::TASK_LAST_RUN, 0.0, $entry->startedAt);

        if (($entry->attributes['failed'] ?? null) === 1) {
            self::add($counters, Metric::TASK_FAILED, 0.0, $entry->startedAt);
        }

        unset($counters);

        $this->addChildren($groups, $minute, $entry);
    }

    /**
     * @param array<string, array{int, float, float}> $counters
     */
    private static function addMemory(array &$counters, string $metric, Entry $entry): void
    {
        $memory = $entry->attributes['memory'] ?? null;
        if (is_numeric($memory) && $memory >= 0 && is_finite((float) $memory)) {
            self::add($counters, $metric, (float) $memory);
        }
    }

    /**
     * @param array<string, array{int, float, float}> $counters
     */
    private static function addTimeSplit(array &$counters, Entry $entry, string $sqlTimeMetric, string $sqlCountMetric, string $httpTimeMetric): void
    {
        $sqlCount = self::number($entry->attributes['sql_n'] ?? null);
        $sqlTime = self::number($entry->attributes['sql_ms'] ?? null);
        if ($sqlCount !== null && $sqlTime !== null && $sqlCount > 0) {
            self::add($counters, $sqlTimeMetric, $sqlTime);
            self::add($counters, $sqlCountMetric, $sqlCount);
        }

        $httpCount = self::number($entry->attributes['http_n'] ?? null);
        $httpTime = self::number($entry->attributes['http_ms'] ?? null);
        if ($httpCount !== null && $httpTime !== null && $httpCount > 0) {
            self::add($counters, $httpTimeMetric, $httpTime);
        }
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addChildren(array &$groups, int $minute, Entry $unit): void
    {
        $weight = self::number($unit->attributes['cw'] ?? null);
        $weight = $weight !== null && $weight >= 1 && $weight <= self::MAX_WEIGHT ? $weight : 1.0;

        $user = null;
        foreach ($unit->children as $child) {
            $occurrences = self::number($child->attributes['n'] ?? null);
            if ($occurrences === null || $occurrences < 0 || $occurrences > self::MAX_OCCURRENCES || !is_finite($child->durationMs)) {
                continue;
            }

            match ($child->type) {
                Entry::TYPE_QUERY => $this->addQuery($groups, $minute, $child, $occurrences, $weight),
                Entry::TYPE_NPLUS1 => $this->addNPlusOne($groups, $minute, $unit, $child, $occurrences, $weight),
                Entry::TYPE_EXCEPTION => $this->addException($groups, $minute, $unit, $child, $occurrences, $weight),
                Entry::TYPE_HTTP => $this->addHttp($groups, $minute, $child, $occurrences, $weight),
                Entry::TYPE_CACHE => $this->addCache($groups, $minute, $child, $weight),
                Entry::TYPE_SENT => $this->addSent($groups, $minute, $child, $occurrences, $weight),
                Entry::TYPE_USER => $user ??= $child,
                default => null,
            };
        }

        if ($user !== null && $unit->type === Entry::TYPE_REQUEST) {
            $this->addUser($groups, $minute, $unit, $user, $weight);
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
     * The maximum of an exception is the start of its unit, which makes it the time of the last occurrence.
     *
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addException(array &$groups, int $minute, Entry $unit, Entry $child, float $occurrences, float $weight): void
    {
        $count = (int) round($occurrences * $weight);
        if ($count < 1) {
            return;
        }

        $byClass = &self::counters($groups, $minute, Family::Exception, $child->key);
        self::add($byClass, Metric::EXCEPTION_COUNT, 0.0, $unit->startedAt, $count);

        $byEntryPoint = &self::counters($groups, $minute, Family::ExceptionWhere, (new EntryPointKey($unit->type, $unit->key, $child->key))->toString());
        self::add($byEntryPoint, Metric::EXCEPTION_WHERE_COUNT, 0.0, $unit->startedAt, $count);
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addHttp(array &$groups, int $minute, Entry $child, float $occurrences, float $weight): void
    {
        $counters = &self::counters($groups, $minute, Family::Http, $child->key);
        self::add($counters, Metric::HTTP_DURATION, $child->durationMs * $weight, self::number($child->attributes['m'] ?? null) ?? 0.0, (int) round($occurrences * $weight));

        foreach (['c4' => Metric::HTTP_STATUS_PREFIX . '4xx', 'c5' => Metric::HTTP_STATUS_PREFIX . '5xx', 'cf' => Metric::HTTP_FAILED] as $attribute => $metric) {
            $count = self::number($child->attributes[$attribute] ?? null) ?? 0.0;
            if ($count > 0 && $count <= self::MAX_OCCURRENCES) {
                self::add($counters, $metric, 0.0, 0.0, (int) round($count * $weight));
            }
        }
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addCache(array &$groups, int $minute, Entry $child, float $weight): void
    {
        $counts = [];
        foreach (self::CACHE_COUNTERS as $attribute => $metric) {
            $count = self::number($child->attributes[$attribute] ?? null) ?? 0.0;
            if ($count > 0 && $count <= self::MAX_OCCURRENCES) {
                $counts[$metric] = (int) round($count * $weight);
            }
        }
        if ($counts === []) {
            return;
        }

        $counters = &self::counters($groups, $minute, Family::Cache, $child->key);
        foreach ($counts as $metric => $count) {
            self::add($counters, $metric, 0.0, 0.0, $count);
        }
    }

    /**
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addSent(array &$groups, int $minute, Entry $child, float $occurrences, float $weight): void
    {
        $count = (int) round($occurrences * $weight);
        if ($count < 1) {
            return;
        }

        $counters = &self::counters($groups, $minute, Family::Message, $child->key);
        self::add($counters, Metric::MESSAGE_SENT, 0.0, 0.0, $count);
    }

    /**
     * A request counts once for its user, whatever the occurrences of the child.
     *
     * @param array<string, array{minute: int, family: Family, key: string, counters: array<string, array{int, float, float}>}> $groups
     */
    private function addUser(array &$groups, int $minute, Entry $unit, Entry $child, float $weight): void
    {
        $count = (int) round($weight);
        $counters = &self::counters($groups, $minute, Family::User, $child->key);
        self::add($counters, Metric::USER_DURATION, $unit->durationMs * $weight, $unit->durationMs, $count);

        if ($unit->durationMs >= $this->slowThresholdMs) {
            self::add($counters, Metric::USER_SLOW, 0.0, 0.0, $count);
        }

        $status = $unit->attributes['status'] ?? null;
        if (is_numeric($status) && $status >= 500) {
            self::add($counters, Metric::USER_ERROR, 0.0, 0.0, $count);
        }
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
            if ($key !== self::OTHER) {
                $weights[$family->value][$key] = ($weights[$family->value][$key] ?? 0.0) + $family->weight($counters);
            }
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
