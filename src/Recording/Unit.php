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

    public function __construct(
        public readonly string $type,
        public readonly float $startedAt,
    ) {}

    /**
     * @param array<string, int> $counters
     */
    public function add(string $type, string $key, float $durationMs, array $counters): void
    {
        if (\strlen($key) > self::MAX_KEY_BYTES) {
            $key = mb_strcut($key, 0, self::MAX_KEY_BYTES, 'UTF-8');
        }
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

    public function sqlTexts(): int
    {
        $texts = 0;
        foreach ($this->sql as $byText) {
            $texts += \count($byText);
        }

        return $texts;
    }

    /**
     * @return array<string, int|float>
     */
    public function totals(): array
    {
        $totals = [];

        if ($this->sql !== []) {
            $totalMs = 0.0;
            $count = 0;
            foreach ($this->sql as $texts) {
                foreach ($texts as [$executions, $ms]) {
                    $count += $executions;
                    $totalMs += $ms;
                }
            }
            $totals['sql_ms'] = round($totalMs, 3);
            $totals['sql_n'] = $count;
        }

        if (isset($this->measures[Entry::TYPE_HTTP])) {
            $totalMs = 0.0;
            $count = 0;
            foreach ($this->measures[Entry::TYPE_HTTP] as [$occurrences, $ms]) {
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
                $attributes = ['n' => $occurrences] + ($max > 0.0 ? ['m' => $max] : []) + $counters;
                $children[] = new Entry((string) $type, (string) $key, $this->startedAt, $ms, $attributes);
            }
        }

        return $children;
    }
}
