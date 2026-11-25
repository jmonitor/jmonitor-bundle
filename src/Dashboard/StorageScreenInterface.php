<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;

interface StorageScreenInterface extends RecordedScreenInterface
{
    /** @return non-empty-array<string, 'asc'|'desc'> sort id => default direction, the default sort first */
    public function sorts(): array;

    /** @return array{title: string, text: string} */
    public function emptyState(): array;

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView;
}
