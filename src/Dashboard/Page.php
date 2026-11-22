<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;

final class Page
{
    /**
     * @param list<Block>                 $blocks
     * @param array<string, 'asc'|'desc'> $sorts
     */
    public function __construct(
        public readonly ScreenInterface $screen,
        public readonly array $blocks,
        public readonly ?Period $period,
        public readonly string $sort,
        public readonly string $direction,
        public readonly array $sorts,
    ) {}

    public function state(): string
    {
        $states = array_unique(array_map(static fn(Block $block): string => $block->state->value, $this->blocks));

        return match (\count($states)) {
            0 => 'ready',
            1 => reset($states),
            default => 'mixed',
        };
    }

    public function isReading(): bool
    {
        foreach ($this->blocks as $block) {
            if ($block->reading) {
                return true;
            }
        }

        return false;
    }

    public function directionFor(string $sort): string
    {
        if ($sort !== $this->sort) {
            return $this->sorts[$sort] ?? 'desc';
        }

        return $this->direction === 'asc' ? 'desc' : 'asc';
    }
}
