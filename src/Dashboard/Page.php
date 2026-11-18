<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;

final class Page
{
    public function __construct(
        public readonly ScreenInterface $screen,
        public readonly ScreenState $state,
        public readonly Period $period,
        public readonly string $sort,
        public readonly string $direction,
        public readonly ScreenView $view,
    ) {}

    public function directionFor(string $sort): string
    {
        if ($sort !== $this->sort) {
            return $this->screen->sorts()[$sort] ?? 'desc';
        }

        return $this->direction === 'asc' ? 'desc' : 'asc';
    }
}
