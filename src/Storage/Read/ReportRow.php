<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Read;

final class ReportRow
{
    /** @param array<string, MetricValue> $metrics metric type => value */
    public function __construct(
        public readonly string $hash,
        public readonly string $key,
        public readonly ?string $label,
        public readonly array $metrics,
    ) {}

    public function count(string $type): int
    {
        return $this->metrics[$type]->count ?? 0;
    }

    public function sum(string $type): float
    {
        return $this->metrics[$type]->sum ?? 0.0;
    }

    public function max(string $type): float
    {
        return $this->metrics[$type]->max ?? 0.0;
    }

    /** @return array<string, int> suffix => count, for the metric types starting with $prefix */
    public function countsByPrefix(string $prefix): array
    {
        $counts = [];
        foreach ($this->metrics as $type => $value) {
            if (str_starts_with((string) $type, $prefix)) {
                $counts[substr((string) $type, \strlen($prefix))] = $value->count;
            }
        }

        return $counts;
    }
}
