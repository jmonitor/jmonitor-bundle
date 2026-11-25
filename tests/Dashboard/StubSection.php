<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class StubSection implements SectionInterface
{
    /** @var list<array{Period, int, string, string}> */
    public array $builds = [];

    /**
     * @param non-empty-string                      $id
     * @param \Closure(): ScreenView|null           $view
     * @param non-empty-array<string, 'asc'|'desc'> $sorts
     */
    public function __construct(
        private readonly string $id = 'stub',
        private readonly ?\Closure $view = null,
        private readonly bool $available = true,
        private readonly array $sorts = ['count' => 'desc', 'name' => 'asc'],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return ucfirst($this->id);
    }

    public function summary(): string
    {
        return 'What the ' . $this->id . ' section shows.';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function sorts(): array
    {
        return $this->sorts;
    }

    public function emptyState(Period $period): array
    {
        return ['title' => 'No ' . $this->id . ' in ' . $period->label() . '.', 'text' => 'Help about ' . $this->id . '.'];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        $this->builds[] = [$period, $now, $sort, $direction];
        $reader->read(new ReportQuery($period, $now, Family::Message));

        return $this->view === null ? new ScreenView() : ($this->view)();
    }
}
