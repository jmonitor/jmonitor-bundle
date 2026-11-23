<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
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

    public function create(ScreenInterface $screen, ?string $period, ?string $sort, ?string $direction, bool $storageFailed = false, bool $fragment = false): Page
    {
        $now = $this->clock->now()->getTimestamp();
        $blocks = [];
        $sorts = [];

        if ($screen instanceof StorageScreenInterface) {
            $period = Period::fromLabel($period ?? '') ?? Period::OneHour;
            $sorts = $screen->sorts();

            if ($sort === null || !isset($sorts[$sort])) {
                $sort = (string) array_key_first($sorts);
                $direction = $sorts[$sort];
            } elseif (!\in_array($direction, ['asc', 'desc'], true)) {
                $direction = $sorts[$sort];
            }

            $blocks = $this->storageBlocks($screen, $period, $now, $sort, $direction, $storageFailed);
        } else {
            $period = null;
            $sort = '';
            $direction = '';
        }

        if ($screen instanceof BlockScreenInterface) {
            $blocks = [...$this->screenBlocks($screen, new ScreenRequest($now, $fragment)), ...$blocks];
        }

        return new Page($screen, $blocks, $period, $sort, $direction, $sorts);
    }

    public function storageUsable(): bool
    {
        return $this->reader !== null && $this->recordingEnabled;
    }

    /** @return list<Block> */
    private function storageBlocks(StorageScreenInterface $screen, Period $period, int $now, string $sort, string $direction, bool $storageFailed): array
    {
        if ($this->reader === null) {
            return [Block::unavailable(Block::TABLE, BlockState::NoConnection)];
        }

        if ($storageFailed) {
            return [Block::unavailable(Block::TABLE, BlockState::StorageError)];
        }

        try {
            $view = $screen->build($this->reader, $period, $now, $sort, $direction);
        } catch (\Throwable $e) {
            $this->logger?->error('JMonitor could not read its data: ' . $e->getMessage(), ['exception' => $e]);

            return [Block::unavailable(Block::TABLE, BlockState::StorageError)];
        }

        if (!$view->isEmpty()) {
            return [Block::cards($view->cards), ...array_map(Block::table(...), $view->tables)];
        }

        if (!$this->recordingEnabled) {
            return [Block::unavailable(Block::TABLE, BlockState::RecordingDisabled)];
        }

        if ($this->bufferDirectory !== null && $this->bufferDirectory->path() === null) {
            return [Block::unavailable(Block::TABLE, BlockState::BufferUnavailable)];
        }

        $empty = $screen->emptyState();

        return [Block::unavailable(Block::TABLE, BlockState::Empty, $empty['title'], $empty['text'])];
    }

    /** @return list<Block> */
    private function screenBlocks(BlockScreenInterface $screen, ScreenRequest $request): array
    {
        try {
            return $screen->blocks($request);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('JMonitor could not display the "%s" screen: %s', $screen->id(), $e->getMessage()), ['exception' => $e]);

            return [Block::unavailable(Block::TABLE, BlockState::Error)];
        }
    }
}
