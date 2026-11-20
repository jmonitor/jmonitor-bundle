<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;

final class Unit
{
    private const MAX_KEY_BYTES = 255;

    /** @var array<string, array<string, array{int, float, float, int}>> dialect value => raw SQL => [executions, total ms, max ms, slow executions] */
    public array $sql = [];

    /** @var array<string, array<string, array{int, float, float, array<string, int>}>> type => key => [occurrences, total ms, max ms, summed counters], types in the order of their first measure */
    private array $measures = [];

    /** @var array<string, int|float> totals carried from the units that ran inside this one */
    private array $carried = [];

    private int $sqlTextBytes = 0;

    public function __construct(
        public readonly string $type,
        public readonly float $startedAt,
        public readonly bool $muted = false,
    ) {}

    /**
     * @param array<string, int> $counters
     */
    public function add(string $type, string $key, float $durationMs, array $counters): void
    {
        if (!is_finite($durationMs)) {
            $durationMs = 0.0;
        }
        $key = self::cut($key, self::MAX_KEY_BYTES);
        if (!isset($this->measures[$type][$key]) && \count($this->measures[$type] ?? []) >= Recorder::MAX_KEYS_PER_TYPE) {
            $key = Aggregator::OTHER;
        }

        $this->measures[$type][$key] ??= [0, 0.0, 0.0, []];
        $measure = &$this->measures[$type][$key];
        ++$measure[0];
        $measure[1] += $durationMs;
        $measure[2] = max($measure[2], $durationMs);
        foreach ($counters as $name => $value) {
            $measure[3][$name] = ($measure[3][$name] ?? 0) + $value;
        }
    }

    public function isEmpty(): bool
    {
        return $this->sql === [] && $this->measures === [];
    }

    /**
     * Reserves room for a new raw SQL text, false when the unit holds too many texts or too many bytes.
     */
    public function retainSqlText(string $sql): bool
    {
        if ($this->sqlTexts() >= Recorder::MAX_SQL_PER_UNIT || $this->sqlTextBytes + \strlen($sql) > Recorder::MAX_SQL_TEXT_BYTES) {
            return false;
        }

        $this->sqlTextBytes += \strlen($sql);

        return true;
    }

    public function sqlTexts(): int
    {
        $texts = 0;
        foreach ($this->sql as $byText) {
            $texts += \count($byText);
        }

        return $texts;
    }

    /**
     * @param array<string, int|float> $totals
     */
    public function carry(array $totals): void
    {
        foreach ($totals as $name => $value) {
            $this->carried[$name] = ($this->carried[$name] ?? 0) + $value;
        }
    }

    /**
     * @return array<string, int|float> own totals plus the carried ones
     */
    public function totals(): array
    {
        $totals = [];

        if ($this->sql !== [] || isset($this->carried['sql_n'])) {
            $totalMs = (float) ($this->carried['sql_ms'] ?? 0.0);
            $count = (int) ($this->carried['sql_n'] ?? 0);
            foreach ($this->sql as $texts) {
                foreach ($texts as [$executions, $ms]) {
                    $count += $executions;
                    $totalMs += $ms;
                }
            }
            $totals['sql_ms'] = round($totalMs, 3);
            $totals['sql_n'] = $count;
        }

        if (isset($this->measures[Entry::TYPE_HTTP]) || isset($this->carried['http_n'])) {
            $totalMs = (float) ($this->carried['http_ms'] ?? 0.0);
            $count = (int) ($this->carried['http_n'] ?? 0);
            foreach ($this->measures[Entry::TYPE_HTTP] ?? [] as [$occurrences, $ms]) {
                $count += $occurrences;
                $totalMs += $ms;
            }
            $totals['http_ms'] = round($totalMs, 3);
            $totals['http_n'] = $count;
        }

        return $totals;
    }

    /**
     * @return list<Entry>
     */
    public function children(?QueryDigester $queries): array
    {
        $children = $this->sql === [] || $queries === null ? [] : $queries->digest($this->sql, $this->startedAt)->children;

        foreach ($this->measures as $type => $keys) {
            foreach ($keys as $key => [$occurrences, $ms, $max, $counters]) {
                $attributes = ['n' => $occurrences] + ($max > 0.0 ? ['m' => round($max, 3)] : []) + $counters;
                $children[] = new Entry((string) $type, (string) $key, $this->startedAt, round($ms, 3), $attributes);
            }
        }

        return $children;
    }

    /**
     * Cuts at the limit, before the last character when its bytes run past it.
     */
    public static function cut(string $key, int $maxBytes): string
    {
        if (\strlen($key) <= $maxBytes) {
            return $key;
        }
        if ($maxBytes <= 0) {
            return '';
        }

        $lead = $maxBytes;
        do {
            $byte = \ord($key[--$lead]);
        } while (($byte & 0xC0) === 0x80 && $lead > max(0, $maxBytes - 4));

        $length = match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };

        return substr($key, 0, $lead + $length > $maxBytes ? $lead : $maxBytes);
    }
}
