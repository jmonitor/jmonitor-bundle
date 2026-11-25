<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\BlockSourceInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;

final class StubSourceSection implements SectionInterface, BlockSourceInterface
{
    /** @var list<ScreenRequest> */
    public array $requests = [];

    /**
     * @param list<Block>|\Throwable $blocks
     */
    public function __construct(
        public readonly StubSection $section,
        private readonly array|\Throwable $blocks = [],
    ) {}

    public function id(): string
    {
        return $this->section->id();
    }

    public function title(): string
    {
        return $this->section->title();
    }

    public function summary(): string
    {
        return $this->section->summary();
    }

    public function isAvailable(): bool
    {
        return $this->section->isAvailable();
    }

    public function sorts(): array
    {
        return $this->section->sorts();
    }

    public function emptyState(Period $period): array
    {
        return $this->section->emptyState($period);
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        return $this->section->build($reader, $period, $now, $sort, $direction);
    }

    public function blocks(ScreenRequest $request): array
    {
        $this->requests[] = $request;

        if ($this->blocks instanceof \Throwable) {
            throw $this->blocks;
        }

        return $this->blocks;
    }
}
