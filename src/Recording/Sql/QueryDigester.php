<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

use Jmonitor\JmonitorBundle\Recording\Entry;

final class QueryDigester
{
    public const OTHER = '(other)';

    private const CACHE_SIZE = 2000;
    private const CACHE_LIFETIME = 600;

    /** @var array<string, array{string, bool}> hash of a raw text => [id, is a select], for labels known to exist */
    private array $known = [];
    private float $knownSince = 0.0;

    public function __construct(
        private readonly LabelStore $labels,
        private readonly int $nPlusOneThreshold = 10,
        private readonly int $maxNewLabels = 20,
    ) {}

    /**
     * @param array<string, array{int, float, float, int}> $raw raw SQL => [executions, total ms, max ms, slow executions]
     */
    public function digest(array $raw, float $startedAt): QueryDigest
    {
        if (\count($this->known) >= self::CACHE_SIZE || abs($startedAt - $this->knownSince) >= self::CACHE_LIFETIME) {
            $this->known = [];
            $this->knownSince = $startedAt;
        }

        /** @var array<string, array{int, float, float, int}> $groups */
        $groups = [];
        $selects = [];
        $other = null;
        $writes = 0;
        $totalMs = 0.0;
        $count = 0;

        foreach ($raw as $sql => $stats) {
            $totalMs += $stats[1];
            $count += $stats[0];

            $sql = (string) $sql;
            $label = $sql === self::OTHER ? null : $this->label($sql, $writes);
            if ($label === null) {
                $other = self::merge($other, $stats);
                continue;
            }

            [$id, $selects[$id]] = $label;
            $groups[$id] = self::merge($groups[$id] ?? null, $stats);
        }

        $children = [];
        foreach ($groups as $id => $group) {
            $children[] = self::query((string) $id, $group, $startedAt);
        }
        if ($other !== null) {
            $children[] = self::query(self::OTHER, $other, $startedAt);
        }
        foreach ($groups as $id => [$n, $ms]) {
            if ($selects[$id] && $n >= $this->nPlusOneThreshold) {
                $children[] = new Entry(Entry::TYPE_NPLUS1, (string) $id, $startedAt, $ms, ['n' => $n]);
            }
        }

        return new QueryDigest($children, $totalMs, $count);
    }

    /**
     * @return array{string, bool}|null null when the text cannot be normalized, or its label does not exist and cannot be written
     */
    private function label(string $sql, int &$writes): ?array
    {
        $hash = hash('xxh128', $sql);
        if (isset($this->known[$hash])) {
            return $this->known[$hash];
        }

        $normalized = SqlNormalizer::normalize($sql);
        if ($normalized === null) {
            return null;
        }

        $id = SqlNormalizer::id($normalized);

        if (!$this->labels->exists($id)) {
            if ($writes >= $this->maxNewLabels) {
                return null;
            }

            ++$writes;
            if (!$this->labels->write($id, $normalized)) {
                return null;
            }
        }

        return $this->known[$hash] = [$id, SqlNormalizer::isSelect($normalized)];
    }

    /**
     * @param array{int, float, float, int}|null $into
     * @param array{int, float, float, int} $stats
     *
     * @return array{int, float, float, int}
     */
    private static function merge(?array $into, array $stats): array
    {
        if ($into === null) {
            return $stats;
        }

        return [$into[0] + $stats[0], $into[1] + $stats[1], max($into[2], $stats[2]), $into[3] + $stats[3]];
    }

    /**
     * @param array{int, float, float, int} $stats
     */
    private static function query(string $key, array $stats, float $startedAt): Entry
    {
        $attributes = ['n' => $stats[0], 'm' => $stats[2]];
        if ($stats[3] > 0) {
            $attributes['sl'] = $stats[3];
        }

        return new Entry(Entry::TYPE_QUERY, $key, $startedAt, $stats[1], $attributes);
    }
}
