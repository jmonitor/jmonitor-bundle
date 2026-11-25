<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;

interface SectionInterface
{
    /** @return non-empty-string matching [a-z][a-z-]*, also the anchor of the section */
    public function id(): string;

    public function title(): string;

    public function summary(): string;

    public function isAvailable(): bool;

    /** @return non-empty-array<string, 'asc'|'desc'> sort id => default direction, the default sort first */
    public function sorts(): array;

    /** @return array{title: string, text: string} */
    public function emptyState(Period $period): array;

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView;
}
