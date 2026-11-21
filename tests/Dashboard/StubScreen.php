<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;

final class StubScreen implements StorageScreenInterface
{
    /** @var list<array{Period, int, string, string}> */
    public array $builds = [];

    /**
     * @param \Closure(ReaderInterface): ScreenView|null $view
     */
    public function __construct(
        private readonly string $id = 'stub',
        private readonly bool $available = true,
        private readonly ?\Closure $view = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return ucfirst($this->id);
    }

    public function heading(): string
    {
        return 'Stub heading';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function sorts(): array
    {
        return ['count' => 'desc', 'name' => 'asc', 'max' => 'desc'];
    }

    public function emptyState(): array
    {
        return ['title' => 'Nothing yet', 'text' => 'No stub recorded yet.'];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        $this->builds[] = [$period, $now, $sort, $direction];

        return $this->view === null ? new ScreenView() : ($this->view)($reader);
    }
}
