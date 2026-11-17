<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Read\RequestSummary;
use Jmonitor\JmonitorBundle\Storage\Read\RouteStats;

final class RequestsScreen
{
    /**
     * @param list<RouteStats> $routes
     * @param list<int> $series
     */
    public function __construct(
        public readonly ScreenState $state,
        public readonly Period $period,
        public readonly string $sort,
        public readonly string $direction,
        public readonly ?RequestSummary $summary = null,
        public readonly array $routes = [],
        public readonly array $series = [],
    ) {}

    public function directionFor(string $sort): string
    {
        if ($sort !== $this->sort) {
            return RequestsScreenFactory::defaultDirection($sort);
        }

        return $this->direction === 'asc' ? 'desc' : 'asc';
    }
}
