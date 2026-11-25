<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Section;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;

final class Page
{
    /**
     * @param list<Block>                 $blocks
     * @param array<string, 'asc'|'desc'> $sorts
     * @param array<string, string>       $query  what a link to this page, its fragment or the rest of it carries
     */
    public function __construct(
        public readonly ScreenInterface $screen,
        public readonly array $blocks,
        public readonly ?Period $period,
        public readonly string $sort,
        public readonly string $direction,
        public readonly array $sorts,
        public readonly array $query = [],
        private readonly ?SubNav $givenTabs = null,
        public readonly ?RaisedChecks $raised = null,
    ) {}

    public function tabs(): ?SubNav
    {
        if ($this->givenTabs !== null) {
            return $this->givenTabs;
        }

        $first = $this->blocks[0] ?? null;

        return $first?->type === Block::SUBNAV && $first->content instanceof SubNav ? $first->content : null;
    }

    /** @return list<Block> */
    public function body(): array
    {
        return $this->givenTabs === null && $this->tabs() !== null ? \array_slice($this->blocks, 1) : $this->blocks;
    }

    public function withTabs(SubNav $tabs): self
    {
        return new self($this->screen, $this->blocks, $this->period, $this->sort, $this->direction, $this->sorts, $this->query, $tabs, $this->raised);
    }

    public function state(): string
    {
        $states = array_unique(self::states($this->blocks));

        return match (\count($states)) {
            0 => 'ready',
            1 => reset($states),
            default => 'mixed',
        };
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<string>
     */
    private static function states(array $blocks): array
    {
        $states = [];

        foreach ($blocks as $block) {
            if (!$block->content instanceof Section) {
                $states[] = $block->state->value;

                continue;
            }

            $states = [...$states, ...self::states($block->content->blocks)];

            if ($block->content->empty !== null) {
                $states[] = BlockState::Empty->value;
            }
        }

        return $states;
    }

    public function hasState(BlockState $state): bool
    {
        return \in_array($state->value, self::states($this->blocks), true);
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
