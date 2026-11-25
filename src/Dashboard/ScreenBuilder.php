<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Section;
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

    public function create(ScreenInterface $screen, ?string $period, ?string $sort, ?string $direction, bool $storageFailed = false, bool $fragment = false, ?string $instance = null): Page
    {
        $now = $this->clock->now()->getTimestamp();

        if ($screen instanceof ComposedScreen) {
            return $this->composedPage($screen, Period::fromLabel($period ?? '') ?? Period::OneHour, $sort, $direction, $storageFailed, new ScreenRequest($now, $fragment, $instance));
        }

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
            $blocks = [...$this->screenBlocks($screen, new ScreenRequest($now, $fragment, $instance)), ...$blocks];
        }

        return new Page($screen, $blocks, $period, $sort, $direction, $sorts, $instance === null ? [] : [ScreenRequest::INSTANCE => $instance]);
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
            return self::figures($view);
        }

        $cause = $this->emptyCause();

        if ($cause !== null) {
            return [Block::unavailable(Block::TABLE, $cause)];
        }

        $empty = $screen->emptyState();

        return [Block::unavailable(Block::TABLE, BlockState::Empty, $empty['title'], $empty['text'])];
    }

    private function composedPage(ComposedScreen $screen, Period $period, ?string $sort, ?string $direction, bool $storageFailed, ScreenRequest $request): Page
    {
        $sections = $screen->sections();
        $chosen = self::chosenSort($sections, $sort, $direction);
        $parts = [];

        foreach ($sections as $section) {
            [$sectionSort, $sectionDirection] = $chosen !== null && $chosen[0] === $section->id() ? [$chosen[1], $chosen[2]] : self::defaultSort($section);
            $own = $section instanceof BlockSourceInterface ? $this->ownBlocks($section, $request) : [];
            $parts[] = [$section, $own, $sectionSort, $sectionDirection];
        }

        $views = [];
        $cause = null;

        if ($this->reader === null) {
            $cause = BlockState::NoConnection;
        } elseif ($storageFailed) {
            $cause = BlockState::StorageError;
        } else {
            foreach ($parts as [$section, , $sectionSort, $sectionDirection]) {
                try {
                    $views[] = $section->build($this->reader, $period, $request->now, $sectionSort, $sectionDirection);
                } catch (\Throwable $e) {
                    $this->logger?->error('JMonitor could not read its data: ' . $e->getMessage(), ['exception' => $e]);
                    $cause = BlockState::StorageError;
                    $views = [];

                    break;
                }
            }

            if ($cause === null && array_filter($views, static fn(ScreenView $view): bool => $view->isEmpty()) !== []) {
                $cause = $this->emptyCause();
            }
        }

        $blocks = $cause === null ? [] : [Block::unavailable(Block::TABLE, $cause)];

        foreach ($parts as $i => [$section, $own, $sectionSort, $sectionDirection]) {
            $view = $views[$i] ?? null;
            $content = $own;
            $empty = null;

            if ($view !== null && !$view->isEmpty()) {
                $content = [...$own, ...self::figures($view)];
            } elseif ($view !== null && $cause === null) {
                $empty = $section->emptyState($period);
            } elseif ($own === []) {
                continue;
            }

            $blocks[] = Block::section(new Section($section->id(), $section->title(), $section->summary(), $content, $sectionSort, $sectionDirection, $section->sorts(), $empty));
        }

        return new Page(
            $screen,
            $blocks,
            $period,
            $chosen === null ? '' : $chosen[0] . '.' . $chosen[1],
            $chosen[2] ?? '',
            [],
            $request->instance === null ? [] : [ScreenRequest::INSTANCE => $request->instance],
        );
    }

    /**
     * @param list<SectionInterface> $sections
     *
     * @return array{string, string, 'asc'|'desc'}|null the section, its sort and the direction, when they name an available section and one of its sorts
     */
    private static function chosenSort(array $sections, ?string $sort, ?string $direction): ?array
    {
        $parts = explode('.', $sort ?? '', 2);

        if (\count($parts) !== 2) {
            return null;
        }

        foreach ($sections as $section) {
            if ($section->id() === $parts[0]) {
                $sorts = $section->sorts();

                if (!isset($sorts[$parts[1]])) {
                    return null;
                }

                return [$parts[0], $parts[1], $direction === 'asc' || $direction === 'desc' ? $direction : $sorts[$parts[1]]];
            }
        }

        return null;
    }

    /** @return array{string, 'asc'|'desc'} */
    private static function defaultSort(SectionInterface $section): array
    {
        $sorts = $section->sorts();
        $sort = (string) array_key_first($sorts);

        return [$sort, $sorts[$sort]];
    }

    /** @return list<Block> */
    private function ownBlocks(SectionInterface&BlockSourceInterface $section, ScreenRequest $request): array
    {
        try {
            return $section->blocks($request);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('JMonitor could not display the "%s" section: %s', $section->id(), $e->getMessage()), ['exception' => $e]);

            return [Block::unavailable(Block::TABLE, BlockState::Error)];
        }
    }

    /** @return list<Block> */
    private static function figures(ScreenView $view): array
    {
        return [Block::cards($view->cards), ...array_map(Block::table(...), $view->tables)];
    }

    private function emptyCause(): ?BlockState
    {
        if (!$this->recordingEnabled) {
            return BlockState::RecordingDisabled;
        }

        if ($this->bufferDirectory !== null && $this->bufferDirectory->path() === null) {
            return BlockState::BufferUnavailable;
        }

        return null;
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
