<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class ScreenBuilder
{
    public function __construct(
        private readonly ?ReaderInterface $reader,
        private readonly bool $recordingEnabled,
        private readonly ClockInterface $clock,
        private readonly ?BufferDirectory $bufferDirectory = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function create(ScreenInterface $screen, ?string $period, ?string $sort, ?string $direction, bool $storageFailed = false): Page
    {
        $period = Period::fromLabel($period ?? '') ?? Period::OneHour;
        $sorts = $screen->sorts();

        if ($sort === null || !isset($sorts[$sort])) {
            $sort = (string) array_key_first($sorts);
            $direction = $sorts[$sort];
        } elseif (!\in_array($direction, ['asc', 'desc'], true)) {
            $direction = $sorts[$sort];
        }

        $page = static fn(ScreenState $state, ScreenView $view = new ScreenView()): Page => new Page($screen, $state, $period, $sort, $direction, $view);

        if ($this->reader === null) {
            return $page(ScreenState::NoConnection);
        }

        if ($storageFailed) {
            return $page(ScreenState::StorageError);
        }

        try {
            $view = $screen->build($this->reader, $period, $this->clock->now()->getTimestamp(), $sort, $direction);
        } catch (\Throwable $e) {
            $this->logger?->error('JMonitor could not read its data: ' . $e->getMessage(), ['exception' => $e]);

            return $page(ScreenState::StorageError);
        }

        if (!$view->isEmpty()) {
            return $page(ScreenState::Ready, $view);
        }

        if (!$this->recordingEnabled) {
            return $page(ScreenState::RecordingDisabled);
        }

        if ($this->bufferDirectory !== null && $this->bufferDirectory->path() === null) {
            return $page(ScreenState::BufferUnavailable);
        }

        return $page(ScreenState::Empty);
    }
}
