<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Brief;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
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
        private readonly ApplicationChecks $checks = new ApplicationChecks([]),
    ) {}

    public function create(ScreenInterface $screen, ?string $period, ?string $sort, ?string $direction, bool $storageFailed = false, bool $fragment = false, ?string $instance = null): Page
    {
        $now = $this->clock->now()->getTimestamp();

        if ($screen instanceof ComposedScreen) {
            return $this->composedPage($screen, Period::fromLabel($period ?? '') ?? Period::OneHour, $sort, $direction, $storageFailed, new ScreenRequest($now, $fragment, $instance));
        }

        $blocks = [];
        $sorts = [];
        $raised = null;

        if ($screen instanceof StorageScreenInterface) {
            $period = Period::fromLabel($period ?? '') ?? Period::OneHour;
            $sorts = $screen->sorts();

            if ($sort === null || !isset($sorts[$sort])) {
                $sort = (string) array_key_first($sorts);
                $direction = $sorts[$sort];
            } elseif (!\in_array($direction, ['asc', 'desc'], true)) {
                $direction = $sorts[$sort];
            }

            [$blocks, $raised] = $this->storageBlocks($screen, $period, $now, $sort, $direction, $storageFailed);
        } else {
            $period = null;
            $sort = '';
            $direction = '';
        }

        if ($screen instanceof BlockScreenInterface) {
            $blocks = [...$this->screenBlocks($screen, new ScreenRequest($now, $fragment, $instance)), ...$blocks];
        }

        return new Page($screen, $blocks, $period, $sort, $direction, $sorts, $instance === null ? [] : [ScreenRequest::INSTANCE => $instance], raised: $raised);
    }

    public function storageUsable(): bool
    {
        return $this->reader !== null && $this->recordingEnabled;
    }

    public function raisedChecks(Page $page): ?RaisedChecks
    {
        if ($page->period === null || $this->reader === null || $page->hasState(BlockState::StorageError)) {
            return null;
        }

        try {
            return $this->checks->raised($this->reader, $page->period, $this->clock->now()->getTimestamp());
        } catch (\Throwable $e) {
            $this->logger?->error('JMonitor could not read the checks of the application: ' . $e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /** @return array{list<Block>, ?RaisedChecks} */
    private function storageBlocks(StorageScreenInterface $screen, Period $period, int $now, string $sort, string $direction, bool $storageFailed): array
    {
        if ($this->reader === null) {
            return [[Block::unavailable(Block::TABLE, BlockState::NoConnection)], null];
        }

        if ($storageFailed) {
            return [[Block::unavailable(Block::TABLE, BlockState::StorageError)], null];
        }

        try {
            $view = $screen->build($this->reader, $period, $now, $sort, $direction);
        } catch (\Throwable $e) {
            $this->logger?->error('JMonitor could not read its data: ' . $e->getMessage(), ['exception' => $e]);

            return [[Block::unavailable(Block::TABLE, BlockState::StorageError)], null];
        }

        if (!$view->isEmpty()) {
            return [self::figures($view), $view->raised];
        }

        $cause = $this->emptyCause();

        if ($cause !== null) {
            return [[Block::unavailable(Block::TABLE, $cause)], $view->raised];
        }

        $empty = $screen->emptyState();

        return [[Block::unavailable(Block::TABLE, BlockState::Empty, $empty['title'], $empty['text'])], $view->raised];
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
        $raised = null;
        $cause = null;

        if ($this->reader === null) {
            $cause = BlockState::NoConnection;
        } elseif ($storageFailed) {
            $cause = BlockState::StorageError;
        } else {
            try {
                $raised = $this->checks->raised($this->reader, $period, $request->now);

                foreach ($parts as [$section, , $sectionSort, $sectionDirection]) {
                    $views[] = $section->build($this->reader, $period, $request->now, $sectionSort, $sectionDirection, $raised);
                }
            } catch (\Throwable $e) {
                $this->logger?->error('JMonitor could not read its data: ' . $e->getMessage(), ['exception' => $e]);
                $cause = BlockState::StorageError;
                $views = [];
                $raised = null;
            }

            if ($cause === null && array_filter($views, static fn(ScreenView $view): bool => $view->isEmpty()) !== []) {
                $cause = $this->emptyCause();
            }
        }

        $blocks = [];
        $briefed = [];
        $shown = [];

        foreach ($parts as $i => [$section, $own, $sectionSort, $sectionDirection]) {
            $view = $views[$i] ?? null;
            $content = $own;
            $empty = null;

            if ($view !== null && !$view->isEmpty()) {
                $content = [...$own, ...self::sectionFigures($view)];
                $shown[] = $view;

                if ($view->brief !== null) {
                    $briefed[] = [$section, $view, $view->brief];
                }
            } elseif ($view !== null && $cause === null) {
                $empty = $section->emptyState($period);
            } elseif ($own === []) {
                continue;
            }

            $blocks[] = Block::section(new Section($section->id(), $section->title(), $section->summary(), $content, $sectionSort, $sectionDirection, $section->sorts(), $empty));
        }

        if ($cause !== null) {
            $blocks = [Block::unavailable(Block::TABLE, $cause), ...$blocks];
        } elseif ($briefed !== []) {
            $blocks = [...self::top($briefed, $period, $screen->outline()), ...$blocks, ...self::guide($shown)];
        }

        return new Page(
            $screen,
            $blocks,
            $period,
            $chosen === null ? '' : $chosen[0] . '.' . $chosen[1],
            $chosen[2] ?? '',
            [],
            $request->instance === null ? [] : [ScreenRequest::INSTANCE => $request->instance],
            raised: $raised,
        );
    }

    /**
     * @param non-empty-list<array{SectionInterface, ScreenView, Brief}> $briefed the sections shown with their figures and a brief, in order
     *
     * @return list<Block> the sentence, the checks, then the cards that lead to each section when the screen has an outline
     */
    private static function top(array $briefed, Period $period, bool $outline): array
    {
        $inPeriod = ' in the ' . strtolower(Format::period($period)) . '.';
        $clauses = array_map(static fn(array $part): string => $part[2]->clause, $briefed);
        $last = array_pop($clauses);
        $sentence = ucfirst(($clauses === [] ? $last : implode(', ', $clauses) . ' and ' . $last) . $inPeriod);
        $detail = implode(' ', array_filter(array_map(static fn(array $part): string => $part[2]->standout, $briefed), static fn(string $standout): bool => $standout !== ''));

        $lines = [];
        $checked = [];

        foreach ($briefed as [, $view]) {
            if ($view->checks !== null) {
                array_push($lines, ...$view->checks->lines);

                if ($view->checks->emptyText !== '') {
                    $checked[] = $view->checks->emptyText;
                }
            }
        }

        $bad = array_values(array_filter($lines, static fn(CheckLine $line): bool => $line->mark->tone === 'bad'));
        $others = array_values(array_filter($lines, static fn(CheckLine $line): bool => $line->mark->tone !== 'bad'));
        $blocks = [
            Block::lead(new Lead($sentence, $detail)),
            Block::checks(new CheckList([...$bad, ...$others], 'Nothing to flag', $checked === [] ? '' : ucfirst(implode(', ', $checked)) . $inPeriod, 'Worth a look')),
        ];

        if ($outline) {
            $blocks[] = Block::cards(array_map(
                static fn(array $part): Card => new Card($part[0]->title(), $part[2]->count, $part[2]->figures, $part[2]->tone, anchor: $part[0]->id()),
                $briefed,
            ));
        }

        return $blocks;
    }

    /**
     * @param list<ScreenView> $shown the views of the sections shown with their figures, in order
     *
     * @return list<Block>
     */
    private static function guide(array $shown): array
    {
        $items = [];

        foreach ($shown as $view) {
            if ($view->guide !== null) {
                array_push($items, ...$view->guide->items);
            }
        }

        return $items === [] ? [] : [Block::guide(new Guide(\array_slice($items, 0, 3)))];
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
    private static function sectionFigures(ScreenView $view): array
    {
        return [...($view->cards === [] ? [] : [Block::cards($view->cards)]), ...array_map(Block::table(...), $view->tables)];
    }

    /** @return list<Block> */
    private static function figures(ScreenView $view): array
    {
        $blocks = [];

        if ($view->lead !== null) {
            $blocks[] = Block::lead($view->lead);
        }

        if ($view->checks !== null) {
            $blocks[] = Block::checks($view->checks);
        }

        if ($view->cards !== [] || $view->lead === null) {
            $blocks[] = Block::cards($view->cards);
        }

        array_push($blocks, ...array_map(Block::table(...), $view->tables));

        if ($view->guide !== null) {
            $blocks[] = Block::guide($view->guide);
        }

        return $blocks;
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
