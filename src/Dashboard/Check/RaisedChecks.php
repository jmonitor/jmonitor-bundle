<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;

final class RaisedChecks
{
    /**
     * @param array<string, CheckInterface> $checks by id, those raised
     * @param array<string, MetricValue>    $totals metric type => total in the period
     */
    public function __construct(
        private readonly array $checks,
        private readonly array $totals,
        private readonly Period $period,
    ) {}

    public function has(string $id): bool
    {
        return isset($this->checks[$id]);
    }

    /** @return array<string, MetricValue> metric type => total, for the metrics of a raised check */
    public function totals(string $id): array
    {
        if (!isset($this->checks[$id])) {
            return [];
        }

        return array_intersect_key($this->totals, array_flip($this->checks[$id]->metrics()));
    }

    public function badge(string ...$screens): ?NavBadge
    {
        $counts = ['critical' => 0, 'warning' => 0];

        foreach ($this->checks as $check) {
            if (\in_array($check->screen(), $screens, true)) {
                ++$counts[$check->severity()->value];
            }
        }

        $count = $counts['critical'] + $counts['warning'];

        if ($count === 0) {
            return null;
        }

        $words = [];

        if ($counts['critical'] > 0) {
            $words[] = $counts['critical'] . ' critical';
        }

        if ($counts['warning'] > 0) {
            $words[] = self::warnings($counts['warning']);
        }

        return new NavBadge(
            $counts['critical'] > 0 ? $count . ' critical' : self::warnings($count),
            $counts['critical'] > 0 ? 'bad' : 'warn',
            implode(', ', $words) . ' in the ' . strtolower(Format::period($this->period)),
        );
    }

    private static function warnings(int $count): string
    {
        return $count === 1 ? '1 warning' : $count . ' warnings';
    }
}
