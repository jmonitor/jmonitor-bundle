<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Page;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\OverviewScreen;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Alert;
use Jmonitor\JmonitorBundle\Dashboard\View\Alerts;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Brief;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Map;
use Jmonitor\JmonitorBundle\Dashboard\View\MapBox;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Place;
use Jmonitor\JmonitorBundle\Dashboard\View\Places;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Section;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;
use Jmonitor\JmonitorBundle\Dashboard\View\Status;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tile;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Storage\Read\Totals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class ScreenBuilderTest extends TestCase
{
    private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->paths);
    }

    public function testInvalidParametersFallBackToTheDefaultsOfTheScreen(): void
    {
        $screen = $this->screen(rows: 1);
        $page = $this->builder()->create($screen, 'abc', 'x', 'sideways');

        static::assertSame(Period::OneHour, $page->period);
        static::assertSame('count', $page->sort);
        static::assertSame('desc', $page->direction);
        static::assertSame([[Period::OneHour, 1800000000, 'count', 'desc']], $screen->builds);
    }

    public function testAnUnknownSortFallsBackToTheFirstSortAndItsDirection(): void
    {
        $builder = $this->builder();

        $page = $builder->create($this->screen(rows: 1), '6h', 'nope', 'asc');
        static::assertSame(Period::SixHours, $page->period);
        static::assertSame('count', $page->sort);
        static::assertSame('desc', $page->direction);

        $page = $builder->create($this->screen(rows: 1), null, 'name', null);
        static::assertSame('name', $page->sort);
        static::assertSame('asc', $page->direction);

        $page = $builder->create($this->screen(rows: 1), null, 'max', 'asc');
        static::assertSame('max', $page->sort);
        static::assertSame('asc', $page->direction);
    }

    public function testDirectionFor(): void
    {
        $page = $this->builder()->create($this->screen(rows: 1), null, 'count', 'desc');

        static::assertSame('asc', $page->directionFor('count'));
        static::assertSame('asc', $page->directionFor('name'));
        static::assertSame('desc', $page->directionFor('max'));
        static::assertSame('desc', $this->builder()->create($this->screen(rows: 1), null, 'name', 'asc')->directionFor('name'));
    }

    public function testStates(): void
    {
        $reader = $this->reader();

        static::assertSame('storage_error', $this->builder($reader)->create($this->screen(rows: 1), null, null, null, true)->state());
        static::assertSame('no_connection', $this->builder(null)->create($this->screen(rows: 1), null, null, null)->state());
        static::assertSame('recording_disabled', $this->builder($reader, false)->create($this->screen(rows: 0), null, null, null)->state());
        static::assertSame('ready', $this->builder($reader, false)->create($this->screen(rows: 1), null, null, null)->state());
        static::assertSame('empty', $this->builder($reader)->create($this->screen(rows: 0), null, null, null)->state());

        $ready = $this->builder($reader)->create($this->screen(rows: 2), '6h', null, null);
        static::assertSame('ready', $ready->state());
        static::assertSame(Period::SixHours, $ready->period);
        $table = $ready->blocks[1]->content;
        static::assertInstanceOf(Table::class, $table);
        static::assertCount(2, $table->rows);
    }

    public function testAFailingReadIsLoggedAndShownAsAStorageError(): void
    {
        $logger = $this->logger();
        $screen = new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => throw new \RuntimeException('Storage is down'));

        $page = (new ScreenBuilder($this->reader(), true, new MockClock('@1800000000'), logger: $logger))->create($screen, null, null, null);

        static::assertSame('storage_error', $page->state());
        static::assertEquals([Block::unavailable(Block::TABLE, BlockState::StorageError)], $page->blocks);
        static::assertSame(['error JMonitor could not read its data: Storage is down'], $logger->messages);
    }

    public function testAnEmptyScreenSaysWhenTheBufferDirectoryIsUnusable(): void
    {
        $file = $this->path();
        touch($file);
        $unusable = new BufferDirectory($file, 'test');
        $directory = $this->path();
        mkdir($directory);
        $usable = new BufferDirectory($directory, 'test');

        static::assertSame('buffer_unavailable', $this->builder(bufferDirectory: $unusable)->create($this->screen(rows: 0), null, null, null)->state());
        static::assertSame('ready', $this->builder(bufferDirectory: $unusable)->create($this->screen(rows: 1), null, null, null)->state());
        static::assertSame('recording_disabled', $this->builder(recordingEnabled: false, bufferDirectory: $unusable)->create($this->screen(rows: 0), null, null, null)->state());
        static::assertSame('empty', $this->builder(bufferDirectory: $usable)->create($this->screen(rows: 0), null, null, null)->state());
    }

    public function testThePageCarriesTheScreenAndItsView(): void
    {
        $screen = $this->screen(rows: 1);
        $page = $this->builder()->create($screen, null, null, null);

        static::assertInstanceOf(Page::class, $page);
        static::assertSame($screen, $page->screen);
        static::assertSame($screen->sorts(), $page->sorts);
        static::assertCount(2, $page->blocks);
        static::assertTrue((new ScreenView())->isEmpty());
        static::assertTrue((new ScreenView(tables: [new Table('t', 'T', [], [])]))->isEmpty());
    }

    public function testAReadyStorageScreenIsACardsBlockThenOneBlockPerTable(): void
    {
        $card = new Card('Hits', '12');
        $first = $this->table(rows: 1);
        $second = $this->table(rows: 0);
        $screen = new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => new ScreenView([$card], [$first, $second]));

        $page = $this->builder()->create($screen, null, null, null);

        static::assertSame([Block::CARDS, Block::TABLE, Block::TABLE], array_map(static fn(Block $block): string => $block->type, $page->blocks));
        static::assertSame([BlockState::Ready, BlockState::Ready, BlockState::Ready], array_map(static fn(Block $block): BlockState => $block->state, $page->blocks));
        static::assertSame([[$card], $first, $second], array_map(static fn(Block $block): mixed => $block->content, $page->blocks));
        static::assertSame([Block::CARDS, Block::TABLE], array_map(static fn(Block $block): string => $block->type, $this->builder()->create(new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => new ScreenView([], [$first])), null, null, null)->blocks), 'A ready screen without cards still has its cards block.');
    }

    public function testAStorageScreenThatIsNotReadyIsOneBlockWithTheEmptyTexts(): void
    {
        $page = $this->builder()->create($this->screen(rows: 0), null, null, null);

        static::assertCount(1, $page->blocks);
        $block = $page->blocks[0];
        static::assertSame([Block::TABLE, BlockState::Empty, null, 'Nothing yet', 'No stub recorded yet.'], [$block->type, $block->state, $block->content, $block->title, $block->text]);

        $block = $this->builder(null)->create($this->screen(rows: 0), null, null, null)->blocks[0];
        static::assertSame([BlockState::NoConnection, '', ''], [$block->state, $block->title, $block->text]);
    }

    public function testABlockScreenShowsItsBlocksWithoutReaderNorPeriod(): void
    {
        $blocks = [Block::cards([new Card('Hits', '12')]), Block::unavailable(Block::TABLE, BlockState::Empty, 'Nothing', 'Not yet.')];
        $screen = new StubBlockScreen(blocks: $blocks);

        $page = $this->builder(null)->create($screen, '6h', 'name', 'asc');

        static::assertSame($blocks, $page->blocks);
        static::assertSame('mixed', $page->state());
        static::assertNull($page->period);
        static::assertSame([], $page->sorts);
        static::assertSame(['', ''], [$page->sort, $page->direction]);
        static::assertEquals([new ScreenRequest(1800000000)], $screen->requests);
    }

    public function testABlockScreenThatThrowsIsLoggedAndShownAsOneErrorBlock(): void
    {
        $logger = $this->logger();
        $screen = new StubBlockScreen(blocks: new \RuntimeException('Probe failed'));

        $page = (new ScreenBuilder(null, true, new MockClock('@1800000000'), logger: $logger))->create($screen, null, null, null);

        static::assertSame('error', $page->state());
        static::assertEquals([Block::unavailable(Block::TABLE, BlockState::Error)], $page->blocks);
        static::assertSame(['error JMonitor could not display the "probe" screen: Probe failed'], $logger->messages);
    }

    public function testAScreenOfBothKindsShowsItsOwnBlocksFirst(): void
    {
        $own = Block::unavailable(Block::CARDS, BlockState::Empty, 'Nothing', 'Not yet.');
        $screen = new class ($this->screen(rows: 1), new StubBlockScreen(blocks: [$own])) implements StorageScreenInterface, BlockScreenInterface {
            public function __construct(
                private readonly StubScreen $storage,
                private readonly StubBlockScreen $blocks,
            ) {}

            public function id(): string
            {
                return 'both';
            }

            public function label(): string
            {
                return 'Both';
            }

            public function heading(): string
            {
                return 'Both kinds';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function space(): Space
            {
                return Space::Application;
            }

            public function sorts(): array
            {
                return $this->storage->sorts();
            }

            public function emptyState(): array
            {
                return $this->storage->emptyState();
            }

            public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
            {
                return $this->storage->build($reader, $period, $now, $sort, $direction);
            }

            public function blocks(ScreenRequest $request): array
            {
                return $this->blocks->blocks($request);
            }
        };

        $page = $this->builder()->create($screen, '6h', null, null);

        static::assertSame([Block::CARDS, Block::CARDS, Block::TABLE], array_map(static fn(Block $block): string => $block->type, $page->blocks));
        static::assertSame($own, $page->blocks[0]);
        static::assertSame('mixed', $page->state());
        static::assertSame(Period::SixHours, $page->period);
        static::assertSame(['count', 'desc'], [$page->sort, $page->direction]);
    }

    public function testTheBlocksOfAScreenFollowTheReadingOrder(): void
    {
        $lead = new Lead('Sentence');
        $checks = new CheckList([]);
        $guide = new Guide([new GuideItem('Term', 'Text')]);
        $card = new Card('Hits', '12');
        $first = $this->table(rows: 1);
        $second = $this->table(rows: 0);
        $view = static fn(ScreenView $view): StubScreen => new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => $view);

        $page = $this->builder()->create($view(new ScreenView([$card], [$first, $second], $lead, $checks, $guide)), null, null, null);

        static::assertSame([Block::LEAD, Block::CHECKS, Block::CARDS, Block::TABLE, Block::TABLE, Block::GUIDE], self::types($page->blocks));
        static::assertSame([$lead, $checks, [$card], $first, $second, $guide], array_map(static fn(Block $block): mixed => $block->content, $page->blocks));
        static::assertSame([Block::LEAD, Block::TABLE, Block::GUIDE], self::types($this->builder()->create($view(new ScreenView([], [$first], $lead, guide: $guide)), null, null, null)->blocks), 'A lead takes the place of an empty list of cards.');
        static::assertSame([Block::CHECKS, Block::CARDS, Block::TABLE], self::types($this->builder()->create($view(new ScreenView([], [$first], checks: $checks)), null, null, null)->blocks), 'Without a lead, an empty list of cards keeps its block.');
        static::assertSame([Block::CARDS, Block::TABLE, Block::GUIDE], self::types($this->builder()->create($view(new ScreenView([$card], [$first], guide: $guide)), null, null, null)->blocks));
    }

    public function testTilesTakeThePlaceOfTheCards(): void
    {
        $tiles = [new Tile('Requests', '12', namesPage: true), new Tile('Average', '7 ms')];
        $first = $this->table(rows: 1);
        $view = static fn(ScreenView $view): StubScreen => new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => $view);

        $page = $this->builder()->create($view(new ScreenView([], [$first], tiles: $tiles)), null, null, null);

        static::assertSame([Block::TILES, Block::TABLE], self::types($page->blocks));
        static::assertSame([$tiles, $first], array_map(static fn(Block $block): mixed => $block->content, $page->blocks));
        static::assertSame([Block::TILES, Block::TABLE], self::types($this->builder()->create($view(new ScreenView([new Card('Hits', '12')], [$first], tiles: $tiles)), null, null, null)->blocks), 'The cards of a view that has tiles are not shown.');
        static::assertSame([Block::CHECKS, Block::TILES, Block::TABLE, Block::GUIDE], self::types($this->builder()->create($view(new ScreenView([], [$first], checks: new CheckList([]), guide: new Guide([new GuideItem('Term', 'Text')]), tiles: $tiles)), null, null, null)->blocks));
        static::assertSame([Block::CARDS, Block::TABLE], self::types($this->builder()->create($view(new ScreenView([], [$first])), null, null, null)->blocks), 'A view without tiles keeps its block of cards.');
    }

    public function testAMapStandsInForTheTiles(): void
    {
        $map = new Map([new MapBox('Requests', '12')]);
        $first = $this->table(rows: 1);
        $screen = new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => new ScreenView([], [$first], map: $map, tiles: [new Tile('Requests', '12', namesPage: true)]));

        $page = $this->builder()->create($screen, null, null, null);

        static::assertSame([Block::MAP, Block::TABLE], self::types($page->blocks));
        static::assertSame([$map, $first], array_map(static fn(Block $block): mixed => $block->content, $page->blocks));
    }

    public function testAnEmptyScreenWithTilesShowsItsStateAlone(): void
    {
        $screen = new StubScreen(view: fn(ReaderInterface $reader): ScreenView => new ScreenView([], [$this->table(rows: 0)], tiles: [new Tile('Requests', '0', namesPage: true)]));

        $page = $this->builder()->create($screen, null, null, null);

        static::assertSame([Block::TABLE], self::types($page->blocks));
        static::assertSame(BlockState::Empty, $page->blocks[0]->state);
    }

    public function testAnEmptyScreenShowsItsStateWithoutLeadNorGuide(): void
    {
        $screen = new StubScreen(view: fn(ReaderInterface $reader): ScreenView => new ScreenView([new Card('Hits', '0')], [$this->table(rows: 0)], new Lead('Sentence'), new CheckList([]), new Guide([new GuideItem('Term', 'Text')])));

        $page = $this->builder()->create($screen, null, null, null);

        static::assertCount(1, $page->blocks);
        static::assertSame([Block::TABLE, BlockState::Empty, 'Nothing yet'], [$page->blocks[0]->type, $page->blocks[0]->state, $page->blocks[0]->title]);
        static::assertSame([Block::TABLE], self::types($this->builder(recordingEnabled: false)->create($screen, null, null, null)->blocks));
    }

    public function testASectionNeverRendersALeadChecksOrGuideOfItsOwn(): void
    {
        $lead = new Lead('Sentence');
        $checks = new CheckList([self::line('a', 'bad')]);
        $guide = new Guide([new GuideItem('Term', 'Text')]);
        $card = new Card('Runs', '3');
        $guided = new StubSection('guided', fn(): ScreenView => new ScreenView([$card], [$this->table(rows: 1)], $lead, $checks, $guide));
        $briefed = new StubSection('briefed', fn(): ScreenView => new ScreenView([], [$this->table(rows: 1)], $lead), brief: new Brief('3', '3 runs'), checks: $checks, guide: $guide);
        $quietGuided = new StubSection('quiet', fn(): ScreenView => new ScreenView([], [$this->table(rows: 0)], $lead, $checks, $guide));
        $notice = Block::notice(new Notice('info', 'Own'));

        foreach ([
            'without a brief' => self::composed($this->section('plain', rows: 1), new StubSourceSection($guided, [$notice]), $quietGuided, $this->section('bare', rows: 0)),
            'beside a brief' => self::composed($this->section('plain', rows: 1), new StubSourceSection($guided, [$notice]), $quietGuided, $this->section('bare', rows: 0), new StubSourceSection($briefed, [$notice])),
        ] as $case => $screen) {
            $page = $this->builder()->create($screen, null, null, null);

            static::assertSame([Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'plain')->blocks), $case);
            static::assertSame([Block::NOTICE, Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'guided')->blocks), $case);
            static::assertSame([$notice, [$card]], [self::sectionOf($page, 'guided')->blocks[0], self::sectionOf($page, 'guided')->blocks[1]->content], $case);
            static::assertSame([], self::sectionOf($page, 'quiet')->blocks, $case);
            static::assertNotNull(self::sectionOf($page, 'quiet')->empty, $case);
            static::assertSame([], self::sectionOf($page, 'bare')->blocks, $case);
        }

        static::assertSame([Block::NOTICE, Block::TABLE], self::types(self::sectionOf($page, 'briefed')->blocks), 'A section without card has no block of cards.');
        static::assertSame([Block::LEAD, Block::CHECKS], \array_slice(self::types($page->blocks), 0, 2));
        static::assertSame([Block::GUIDE], \array_slice(self::types($page->blocks), -1));
    }

    public function testTheScreenRequestSaysWhenItIsAFollowUp(): void
    {
        $screen = new StubBlockScreen();
        $builder = $this->builder(null);

        $builder->create($screen, null, null, null);
        $builder->create($screen, null, null, null, false, true);

        static::assertEquals([new ScreenRequest(1800000000), new ScreenRequest(1800000000, true)], $screen->requests);
        static::assertSame([false, true], array_map(static fn(ScreenRequest $request): bool => $request->fragment, $screen->requests));
    }

    public function testAPageIsReadingWhileOneOfItsBlocksIs(): void
    {
        $cards = Block::cards([new Card('Hits', '12')]);

        static::assertFalse($this->builder(null)->create(new StubBlockScreen(blocks: [$cards]), null, null, null)->isReading());
        static::assertTrue($this->builder(null)->create(new StubBlockScreen(blocks: [$cards, $cards->withReading()]), null, null, null)->isReading());
        static::assertTrue($this->builder(null)->create(new StubBlockScreen(blocks: [$cards, Block::unavailable(Block::TABLE, BlockState::Reading)]), null, null, null)->isReading());
    }

    public function testAComposedScreenShowsEachAvailableSectionInOrder(): void
    {
        $first = $this->section('first', rows: 2);
        $second = $this->section('second', rows: 1);

        $page = $this->builder()->create(self::composed($first, $second), '6h', null, null);

        static::assertSame(['section first', 'section second'], self::shape($page));
        static::assertSame('ready', $page->state());
        static::assertSame(Period::SixHours, $page->period);
        static::assertSame(['', '', []], [$page->sort, $page->direction, $page->sorts]);
        $section = self::sectionOf($page, 'first');
        static::assertSame(['First', 'What the first section shows.'], [$section->title, $section->summary]);
        static::assertSame([Block::CARDS, Block::TABLE], self::types($section->blocks));
        static::assertNull($section->empty);
        static::assertSame([[Period::SixHours, 1800000000, 'count', 'desc']], $first->builds);
        static::assertSame([[Period::SixHours, 1800000000, 'count', 'desc']], $second->builds);
        static::assertSame(Period::OneHour, $this->builder()->create(self::composed($first), 'abc', null, null)->period);
    }

    public function testAnUnavailableSectionIsLeftOut(): void
    {
        $hidden = $this->section('hidden', rows: 1, available: false);

        $page = $this->builder()->create(self::composed($this->section('first', rows: 1), $hidden, $this->section('last', rows: 1)), null, null, null);

        static::assertSame(['section first', 'section last'], self::shape($page));
        static::assertSame([], $hidden->builds);
    }

    public function testAnEmptySectionIsOneLineBetweenTheOthers(): void
    {
        $page = $this->builder()->create(self::composed($this->section('first', rows: 1), $this->section('quiet', rows: 0), $this->section('last', rows: 1)), '6h', null, null);

        static::assertSame(['section first', 'section quiet', 'section last'], self::shape($page));
        static::assertSame('mixed', $page->state());
        $quiet = self::sectionOf($page, 'quiet');
        static::assertSame([], $quiet->blocks);
        static::assertSame(['title' => 'No quiet in 6h.', 'text' => 'Help about quiet.'], $quiet->empty);
        static::assertSame([Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'last')->blocks));
    }

    public function testASectionKeepsItsOwnBlocksAboveItsFigures(): void
    {
        $notice = Block::notice(new Notice('info', 'Own'));
        $full = new StubSourceSection($this->section('full', rows: 1), [$notice]);
        $quiet = new StubSourceSection($this->section('quiet', rows: 0), [$notice]);

        $page = $this->builder()->create(self::composed($full, $quiet), null, null, null, false, true, 'reports');

        static::assertSame([Block::NOTICE, Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'full')->blocks));
        static::assertSame($notice, self::sectionOf($page, 'full')->blocks[0]);
        static::assertNull(self::sectionOf($page, 'full')->empty);
        static::assertSame([$notice], self::sectionOf($page, 'quiet')->blocks);
        static::assertNotNull(self::sectionOf($page, 'quiet')->empty);
        static::assertEquals([new ScreenRequest(1800000000, true, 'reports')], $full->requests);
        static::assertSame(['connection' => 'reports'], $page->query);
        static::assertSame([], $this->builder()->create(self::composed($full), null, null, null)->query);
    }

    public function testWithoutAConnectionTheStateIsToldOnceAndOwnBlocksStay(): void
    {
        $notice = Block::notice(new Notice('info', 'Own'));
        $plain = $this->section('plain', rows: 1);
        $own = new StubSourceSection($this->section('own', rows: 1), [$notice]);
        $none = new StubSourceSection($this->section('none', rows: 1));

        $page = $this->builder(null)->create(self::composed($plain, $own, $none), null, null, null);

        static::assertSame(['table no_connection', 'section own'], self::shape($page));
        static::assertEquals(Block::unavailable(Block::TABLE, BlockState::NoConnection), $page->blocks[0]);
        static::assertSame([$notice], self::sectionOf($page, 'own')->blocks);
        static::assertNull(self::sectionOf($page, 'own')->empty);
        static::assertSame([], $plain->builds);
        static::assertSame([], $own->section->builds);
        static::assertSame('no_connection', $this->builder(null)->create(self::composed($plain), null, null, null)->state());
    }

    public function testAFailedFlushIsToldOnce(): void
    {
        $reader = new FixedReader();
        $notice = Block::notice(new Notice('info', 'Own'));
        $plain = $this->section('plain', rows: 1);

        $page = $this->builder($reader)->create(self::composed($plain, new StubSourceSection($this->section('own', rows: 1), [$notice]), $this->section('other', rows: 1)), null, null, null, true);

        static::assertSame(['table storage_error', 'section own'], self::shape($page));
        static::assertSame([$notice], self::sectionOf($page, 'own')->blocks);
        static::assertSame([], $reader->queries);
        static::assertSame([], $plain->builds);
    }

    public function testAReadThatThrowsStopsTheNextOnes(): void
    {
        $reader = new FixedReader(static fn(): Report => throw new \RuntimeException('Storage is down'));
        $logger = $this->logger();
        $notice = Block::notice(new Notice('info', 'Own'));
        $first = $this->section('first', rows: 1);
        $second = $this->section('second', rows: 1);
        $own = new StubSourceSection($this->section('own', rows: 1), [$notice]);

        $page = (new ScreenBuilder($reader, true, new MockClock('@1800000000'), logger: $logger))->create(self::composed($first, $second, $own), null, null, null);

        static::assertCount(1, $reader->queries);
        static::assertCount(1, $first->builds);
        static::assertSame([], $second->builds);
        static::assertSame([], $own->section->builds);
        static::assertSame(['table storage_error', 'section own'], self::shape($page));
        static::assertSame([$notice], self::sectionOf($page, 'own')->blocks);
        static::assertSame(['error JMonitor could not read its data: Storage is down'], $logger->messages);
    }

    public function testAReadThatThrowsTakesTheFiguresOfTheSectionsAlreadyRead(): void
    {
        $reads = 0;
        $reader = new FixedReader(static function () use (&$reads): Report {
            if (++$reads === 2) {
                throw new \RuntimeException('Storage is down');
            }

            return new Report();
        });
        $notice = Block::notice(new Notice('info', 'Own'));
        $first = new StubSourceSection($this->section('first', rows: 1), [$notice]);

        $page = (new ScreenBuilder($reader, true, new MockClock('@1800000000'), logger: $this->logger()))->create(self::composed($first, $this->section('second', rows: 1), $this->section('third', rows: 1)), null, null, null);

        static::assertCount(1, $first->section->builds);
        static::assertCount(2, $reader->queries);
        static::assertSame(['table storage_error', 'section first'], self::shape($page));
        static::assertSame([$notice], self::sectionOf($page, 'first')->blocks);
        static::assertNull(self::sectionOf($page, 'first')->empty);
    }

    public function testRecordingOffIsToldOnceWhenEverySectionIsEmpty(): void
    {
        $notice = Block::notice(new Notice('info', 'Own'));

        $page = $this->builder(recordingEnabled: false)->create(self::composed($this->section('first', rows: 0), new StubSourceSection($this->section('own', rows: 0), [$notice]), $this->section('last', rows: 0)), null, null, null);

        static::assertSame(['table recording_disabled', 'section own'], self::shape($page));
        static::assertSame([$notice], self::sectionOf($page, 'own')->blocks);
        static::assertNull(self::sectionOf($page, 'own')->empty);
    }

    public function testAnUnusableBufferDirectoryIsToldOnceWhenEverySectionIsEmpty(): void
    {
        $file = $this->path();
        touch($file);
        $unusable = new BufferDirectory($file, 'test');
        $notice = Block::notice(new Notice('info', 'Own'));
        $screen = self::composed($this->section('first', rows: 0), new StubSourceSection($this->section('own', rows: 0), [$notice]));

        $page = $this->builder(bufferDirectory: $unusable)->create($screen, null, null, null);

        static::assertSame(['table buffer_unavailable', 'section own'], self::shape($page));
        static::assertSame([$notice], self::sectionOf($page, 'own')->blocks);
        static::assertSame(['table recording_disabled', 'section own'], self::shape($this->builder(recordingEnabled: false, bufferDirectory: $unusable)->create($screen, null, null, null)));
    }

    public function testRecordingOffSaysNothingWhenEverySectionHoldsFigures(): void
    {
        $page = $this->builder(recordingEnabled: false)->create(self::composed($this->section('first', rows: 1), $this->section('last', rows: 2)), null, null, null);

        static::assertSame(['section first', 'section last'], self::shape($page));
        static::assertSame('ready', $page->state());
        static::assertSame([Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'last')->blocks));
    }

    public function testRecordingOffStillShowsWhatThePeriodHolds(): void
    {
        $notice = Block::notice(new Notice('info', 'Own'));

        $page = $this->builder(recordingEnabled: false)->create(self::composed($this->section('full', rows: 1), $this->section('quiet', rows: 0), new StubSourceSection($this->section('own', rows: 0), [$notice])), null, null, null);

        static::assertSame(['table recording_disabled', 'section full', 'section own'], self::shape($page));
        static::assertSame([Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'full')->blocks));
        static::assertNull(self::sectionOf($page, 'full')->empty);
        static::assertSame([$notice], self::sectionOf($page, 'own')->blocks);
        static::assertNull(self::sectionOf($page, 'own')->empty);
    }

    public function testAnUnusableBufferDirectorySaysNothingWhenEverySectionHoldsFigures(): void
    {
        $file = $this->path();
        touch($file);

        $page = $this->builder(bufferDirectory: new BufferDirectory($file, 'test'))->create(self::composed($this->section('first', rows: 1), $this->section('last', rows: 2)), null, null, null);

        static::assertSame(['section first', 'section last'], self::shape($page));
        static::assertSame('ready', $page->state());
    }

    public function testAnUnusableBufferDirectoryStillShowsWhatThePeriodHolds(): void
    {
        $file = $this->path();
        touch($file);
        $unusable = new BufferDirectory($file, 'test');
        $notice = Block::notice(new Notice('info', 'Own'));
        $screen = self::composed($this->section('full', rows: 1), $this->section('quiet', rows: 0), new StubSourceSection($this->section('own', rows: 0), [$notice]));

        $page = $this->builder(bufferDirectory: $unusable)->create($screen, null, null, null);

        static::assertSame(['table buffer_unavailable', 'section full', 'section own'], self::shape($page));
        static::assertSame([Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'full')->blocks));
        static::assertSame([$notice], self::sectionOf($page, 'own')->blocks);
        static::assertNull(self::sectionOf($page, 'own')->empty);
        static::assertSame(['table recording_disabled', 'section full', 'section own'], self::shape($this->builder(recordingEnabled: false, bufferDirectory: $unusable)->create($screen, null, null, null)));
    }

    public function testTheChosenSortOnlySortsItsSection(): void
    {
        $first = $this->section('first', rows: 1);
        $second = $this->section('second', rows: 1, sorts: ['count' => 'desc', 'name' => 'asc', 'max' => 'desc']);
        $screen = self::composed($first, $second);

        $page = $this->builder()->create($screen, null, 'second.name', null);

        static::assertSame(['second.name', 'asc'], [$page->sort, $page->direction]);
        static::assertSame([[Period::OneHour, 1800000000, 'count', 'desc']], $first->builds);
        static::assertSame([[Period::OneHour, 1800000000, 'name', 'asc']], $second->builds);
        static::assertSame(['count', 'desc', ['count' => 'desc', 'name' => 'asc']], [self::sectionOf($page, 'first')->sort, self::sectionOf($page, 'first')->direction, self::sectionOf($page, 'first')->sorts]);
        static::assertSame(['name', 'asc'], [self::sectionOf($page, 'second')->sort, self::sectionOf($page, 'second')->direction]);
        static::assertSame('desc', self::sectionOf($page, 'second')->directionFor('name'));
        static::assertSame('desc', self::sectionOf($page, 'second')->directionFor('max'));
        static::assertSame('asc', self::sectionOf($page, 'first')->directionFor('count'));
        static::assertSame('asc', self::sectionOf($page, 'first')->directionFor('name'));

        $page = $this->builder()->create($screen, null, 'second.max', 'asc');
        static::assertSame(['second.max', 'asc'], [$page->sort, $page->direction]);

        $page = $this->builder()->create($screen, null, 'second.name', 'sideways');
        static::assertSame(['second.name', 'asc'], [$page->sort, $page->direction]);
        static::assertSame(['count', 'desc'], [self::sectionOf($page, 'first')->sort, self::sectionOf($page, 'first')->direction]);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unknownSorts(): iterable
    {
        yield 'not given' => [null];
        yield 'empty' => [''];
        yield 'a section without column' => ['commands.'];
        yield 'a section alone' => ['commands'];
        yield 'a column without section' => ['.runs'];
        yield 'an unknown section' => ['x.y'];
        yield 'an unavailable section' => ['hidden.runs'];
        yield 'a column of another section' => ['commands.handled'];
        yield 'a column of a section with a suffix' => ['commands.runs.x'];
        yield 'a section in upper case' => ['Commands.runs'];
    }

    #[DataProvider('unknownSorts')]
    public function testAnUnknownSortChoosesNothing(?string $sort): void
    {
        $messages = $this->section('messages', rows: 1, sorts: ['handled' => 'desc', 'name' => 'asc']);
        $commands = $this->section('commands', rows: 1, sorts: ['runs' => 'desc', 'name' => 'asc']);
        $hidden = $this->section('hidden', rows: 1, available: false, sorts: ['runs' => 'desc']);

        $page = $this->builder()->create(self::composed($messages, $commands, $hidden), null, $sort, 'asc');

        static::assertSame(['', ''], [$page->sort, $page->direction]);
        static::assertSame([[Period::OneHour, 1800000000, 'handled', 'desc']], $messages->builds);
        static::assertSame([[Period::OneHour, 1800000000, 'runs', 'desc']], $commands->builds);
        static::assertSame(['runs', 'desc'], [self::sectionOf($page, 'commands')->sort, self::sectionOf($page, 'commands')->direction]);
    }

    public function testAnOwnBlockThatThrowsOnlyBreaksItsSection(): void
    {
        $logger = $this->logger();
        $notice = Block::notice(new Notice('info', 'Own'));
        $screen = self::composed(new StubSourceSection($this->section('broken', rows: 1), new \RuntimeException('Queues failed')), new StubSourceSection($this->section('other', rows: 1), [$notice]));

        $page = (new ScreenBuilder($this->reader(), true, new MockClock('@1800000000'), logger: $logger))->create($screen, null, null, null);

        static::assertSame(['section broken', 'section other'], self::shape($page));
        static::assertSame([Block::TABLE, Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'broken')->blocks));
        static::assertEquals(Block::unavailable(Block::TABLE, BlockState::Error), self::sectionOf($page, 'broken')->blocks[0]);
        static::assertSame([Block::NOTICE, Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'other')->blocks));
        static::assertSame(['error JMonitor could not display the "broken" section: Queues failed'], $logger->messages);

        $page = (new ScreenBuilder(null, true, new MockClock('@1800000000')))->create($screen, null, null, null);

        static::assertSame(['table no_connection', 'section broken', 'section other'], self::shape($page));
        static::assertEquals([Block::unavailable(Block::TABLE, BlockState::Error)], self::sectionOf($page, 'broken')->blocks);
    }

    public function testAReadingOwnBlockMarksThePage(): void
    {
        $reading = new StubSourceSection($this->section('queues', rows: 0), [Block::unavailable(Block::FACTS, BlockState::Reading)]);
        $screen = self::composed($this->section('first', rows: 1), $reading);

        $page = $this->builder()->create($screen, null, null, null);

        static::assertTrue($page->isReading());
        static::assertSame([false, true], array_map(static fn(Block $block): bool => $block->reading, $page->blocks));
        static::assertTrue($this->builder(null)->create($screen, null, null, null)->isReading());
        static::assertFalse($this->builder()->create(self::composed($this->section('first', rows: 1), $this->section('quiet', rows: 0)), null, null, null)->isReading());
    }

    public function testTheRaisedChecksOfAViewGoToThePageAndStayWithItsTabs(): void
    {
        $raised = (new ApplicationChecks([new ServerErrorsCheck()]))->raised(new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]), Period::OneHour, 1800000000);
        $table = $this->table(1);

        $page = $this->builder()->create(new StubScreen(view: static fn(): ScreenView => new ScreenView([], [$table], raised: $raised)), null, null, null);

        static::assertSame($raised, $page->raised);
        static::assertSame($raised, $page->withTabs(new SubNav('Application', []))->raised);
        static::assertNull($this->builder()->create($this->screen(rows: 1), null, null, null)->raised);
    }

    public function testTheChecksAreReadForAPageWithAPeriod(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]);
        $builder = new ScreenBuilder($reader, true, new MockClock('@1800000000'), null, null, new ApplicationChecks([new ServerErrorsCheck()]));
        $page = $builder->create($this->screen(rows: 1), '6h', null, null);

        static::assertTrue($builder->raisedChecks($page)?->has('requests.server_errors'));
        static::assertSame([[Period::SixHours, 1800000000, [self::SERVER_ERRORS]]], $reader->totalRequests);

        static::assertNull($builder->raisedChecks($builder->create($this->screen(rows: 1), '6h', null, null, storageFailed: true)));
        static::assertNull($builder->raisedChecks($builder->create(new StubScreen(view: static fn(): ScreenView => throw new \RuntimeException('Gone')), null, null, null)));
        static::assertNull($builder->raisedChecks($builder->create(new StubBlockScreen('probe'), null, null, null)));
        static::assertCount(1, $reader->totalRequests);
    }

    public function testTheChecksAreNotReadWithoutReader(): void
    {
        $builder = new ScreenBuilder(null, true, new MockClock('@1800000000'), null, null, new ApplicationChecks([new ServerErrorsCheck()]));

        static::assertNull($builder->raisedChecks($builder->create($this->screen(rows: 1), null, null, null)));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function sentences(): iterable
    {
        yield 'one part' => [['42 messages handled'], '42 messages handled in the last 6 hours.'];
        yield 'two parts' => [['1,668 cache reads (96 % found)', '13 outgoing calls'], '1,668 cache reads (96 % found) and 13 outgoing calls in the last 6 hours.'];
        yield 'three parts' => [['42 messages handled', '12 scheduled runs', '3 command runs'], '42 messages handled, 12 scheduled runs and 3 command runs in the last 6 hours.'];
        yield 'four parts' => [['42 messages handled', '12 scheduled runs', '3 command runs', '9 cache reads'], '42 messages handled, 12 scheduled runs, 3 command runs and 9 cache reads in the last 6 hours.'];
        yield 'a part that starts in lower case' => [['one run'], 'One run in the last 6 hours.'];
    }

    /**
     * @param list<string> $clauses
     */
    #[DataProvider('sentences')]
    public function testAComposedPageOpensWithOneSentenceForItsSections(array $clauses, string $sentence): void
    {
        $sections = array_map(fn(int $i, string $clause): StubSection => $this->briefed('s' . $i, new Brief((string) $i, $clause)), array_keys($clauses), $clauses);

        $page = $this->builder()->create(self::composed(...$sections), '6h', null, null);

        static::assertSame(['lead ready', 'checks ready', ...array_map(static fn(int $i): string => 'section s' . $i, array_keys($clauses))], self::shape($page));
        static::assertEquals(new Lead($sentence), $page->blocks[0]->content);
        static::assertSame('ready', $page->state());
    }

    public function testTheStandoutsOfTheSectionsMakeTheDetail(): void
    {
        $page = $this->builder()->create(self::composed(
            $this->briefed('quiet', new Brief('3', '3 runs')),
            $this->briefed('failing', new Brief('2', '2 calls', standout: '1 call got no answer.')),
            $this->briefed('low', new Brief('9', '9 reads', standout: '2 pools have a low hit rate.')),
        ), null, null, null);

        static::assertEquals(new Lead('3 runs, 2 calls and 9 reads in the last hour.', '1 call got no answer. 2 pools have a low hit rate.'), $page->blocks[0]->content);
        static::assertSame('', $this->builder()->create(self::composed($this->briefed('quiet', new Brief('3', '3 runs'))), null, null, null)->blocks[0]->content->detail);
    }

    public function testTheChecksOfTheSectionsAreOneBlockMostSevereFirst(): void
    {
        $first = new CheckList([self::line('first.warn', 'warn'), self::line('first.bad', 'bad'), self::line('first.other', 'warn')], 'Nothing to flag', 'no first problem', 'Worth a look');
        $second = new CheckList([self::line('second.warn', 'warn'), self::line('second.bad', 'bad')], 'Nothing to flag', 'no second problem', 'Worth a look');

        $page = $this->builder()->create(self::composed(
            $this->briefed('first', new Brief('1', '1 first'), $first),
            $this->briefed('quiet', new Brief('1', '1 quiet')),
            $this->briefed('second', new Brief('1', '1 second'), $second),
        ), null, null, null);

        $checks = $page->blocks[1]->content;
        static::assertInstanceOf(CheckList::class, $checks);
        static::assertSame(['first.bad', 'second.bad', 'first.warn', 'first.other', 'second.warn'], array_map(static fn(CheckLine $line): string => $line->id, $checks->lines));
        static::assertSame(['Worth a look', 'Nothing to flag'], [$checks->title, $checks->emptyTitle]);
    }

    public function testWithoutCheckTheBlockSaysWhatEachSectionChecked(): void
    {
        $page = $this->builder()->create(self::composed(
            $this->briefed('messages', new Brief('4', '4 messages handled'), new CheckList([], emptyText: 'no failed message')),
            $this->briefed('silent', new Brief('1', '1 silent'), new CheckList([])),
            $this->briefed('none', new Brief('1', '1 none')),
            $this->briefed('commands', new Brief('2', '2 command runs'), new CheckList([], emptyText: 'no failed command run')),
        ), '24h', null, null);

        static::assertEquals(new CheckList([], 'Nothing to flag', 'No failed message, no failed command run in the last 24 hours.', 'Worth a look'), $page->blocks[1]->content);

        $page = $this->builder()->create(self::composed($this->briefed('none', new Brief('1', '1 none')), $this->briefed('silent', new Brief('1', '1 silent'), new CheckList([]))), null, null, null);

        static::assertSame([Block::LEAD, Block::CHECKS], \array_slice(self::types($page->blocks), 0, 2));
        static::assertEquals(new CheckList([], 'Nothing to flag', '', 'Worth a look'), $page->blocks[1]->content);
    }

    public function testASectionWithoutChecksBringsNeitherLineNorText(): void
    {
        $page = $this->builder()->create(self::composed(
            $this->briefed('none', new Brief('1', '1 none'), guide: new Guide([new GuideItem('Term', 'Text')])),
            $this->briefed('cache', new Brief('9', '9 reads'), new CheckList([self::line('cache.low_hit_rate', 'warn')], emptyText: 'no pool with a low hit rate')),
        ), null, null, null);

        $checks = $page->blocks[1]->content;
        static::assertInstanceOf(CheckList::class, $checks);
        static::assertSame(['cache.low_hit_rate'], array_map(static fn(CheckLine $line): string => $line->id, $checks->lines));
        static::assertSame('No pool with a low hit rate in the last hour.', $checks->emptyText);
    }

    public function testAnOutlineScreenShowsOneAnchorCardPerBrief(): void
    {
        $sections = [
            $this->briefed('messages', new Brief('42', '42 messages handled', 'handled, 45 sent, 3 failed', '3 messages failed.', 'bad'), guide: new Guide([new GuideItem('Sent and handled.', 'Text')])),
            $this->section('plain', rows: 1),
            $this->briefed('commands', new Brief('3', '3 command runs', 'runs of 2 commands')),
        ];

        $page = $this->builder()->create(new ComposedScreen('background', 'Background', 'Work done in the background', Space::Application, $sections, true), null, null, null);

        static::assertSame(['lead ready', 'checks ready', 'cards ready', 'section messages', 'section plain', 'section commands', 'guide ready'], self::shape($page));
        static::assertEquals([
            new Card('Messages', '42', 'handled, 45 sent, 3 failed', 'bad', anchor: 'messages'),
            new Card('Commands', '3', 'runs of 2 commands', anchor: 'commands'),
        ], $page->blocks[2]->content);
        static::assertSame(['lead ready', 'checks ready', 'section messages', 'section plain', 'section commands', 'guide ready'], self::shape($this->builder()->create(self::composed(...$sections), null, null, null)));
    }

    public function testTheGuideKeepsTheFirstThreeItemsOfTheSections(): void
    {
        $item = static fn(string $term): GuideItem => new GuideItem($term, 'Text of ' . $term);
        $unbriefed = new StubSection('unbriefed', fn(): ScreenView => new ScreenView([], [$this->table(rows: 1)]), guide: new Guide([$item('unbriefed')]));

        $page = $this->builder()->create(self::composed(
            $unbriefed,
            $this->briefed('first', new Brief('1', '1 first'), guide: new Guide([$item('a'), $item('b')])),
            $this->briefed('none', new Brief('1', '1 none')),
            $this->briefed('second', new Brief('1', '1 second'), guide: new Guide([$item('c'), $item('d')])),
        ), null, null, null);

        $guide = $page->blocks[\count($page->blocks) - 1]->content;
        static::assertInstanceOf(Guide::class, $guide);
        static::assertSame(['unbriefed', 'a', 'b'], array_map(static fn(GuideItem $item): string => $item->term, $guide->items));

        $page = $this->builder()->create(self::composed($this->briefed('first', new Brief('1', '1 first')), $this->briefed('none', new Brief('1', '1 none'))), null, null, null);

        static::assertSame(['lead ready', 'checks ready', 'section first', 'section none'], self::shape($page));
    }

    public function testWithoutAnyBriefThePageIsTheOneOfToday(): void
    {
        $checks = new CheckList([self::line('a', 'bad')]);
        $guide = new Guide([new GuideItem('Term', 'Text')]);
        $guided = new StubSection('guided', fn(): ScreenView => new ScreenView([new Card('Runs', '3')], [$this->table(rows: 1)], checks: $checks, guide: $guide));

        $page = $this->builder()->create(new ComposedScreen('background', 'Background', 'Work done in the background', Space::Application, [$this->section('first', rows: 1), $guided, $this->section('quiet', rows: 0)], true), null, null, null);

        static::assertSame(['section first', 'section guided', 'section quiet'], self::shape($page));
        static::assertSame('mixed', $page->state());
    }

    public function testAnEmptySectionBringsNothingEvenWithItsBrief(): void
    {
        $checks = new CheckList([self::line('quiet.bad', 'bad')], emptyText: 'no quiet problem');
        $guide = new Guide([new GuideItem('Quiet', 'Text')]);
        $quiet = new StubSection('quiet', fn(): ScreenView => new ScreenView([new Card('Runs', '0')], [$this->table(rows: 0)]), brief: new Brief('0', '0 quiet runs', 'runs', 'Nothing ran.', 'bad'), checks: $checks, guide: $guide);
        $sections = [$this->briefed('first', new Brief('2', '2 runs')), $quiet];

        $page = $this->builder()->create(new ComposedScreen('background', 'Background', 'Work done in the background', Space::Application, $sections, true), null, null, null);

        static::assertSame(['lead ready', 'checks ready', 'cards ready', 'section first', 'section quiet'], self::shape($page));
        static::assertEquals(new Lead('2 runs in the last hour.'), $page->blocks[0]->content);
        static::assertEquals(new CheckList([], 'Nothing to flag', '', 'Worth a look'), $page->blocks[1]->content);
        static::assertEquals([new Card('First', '2', anchor: 'first')], $page->blocks[2]->content);
        static::assertNotNull(self::sectionOf($page, 'quiet')->empty);

        $page = $this->builder()->create(new ComposedScreen('background', 'Background', 'Work done in the background', Space::Application, [$quiet, $this->section('plain', rows: 1)], true), null, null, null);

        static::assertSame(['section quiet', 'section plain'], self::shape($page));
    }

    public function testACauseOfTheWholePageLeavesNoSentence(): void
    {
        $file = $this->path();
        touch($file);
        $notice = Block::notice(new Notice('info', 'Own'));
        $guide = new Guide([new GuideItem('Term', 'Text')]);
        $screen = self::composed(
            new StubSourceSection($this->briefed('own', new Brief('2', '2 runs'), guide: $guide), [$notice]),
            $this->briefed('other', new Brief('1', '1 call'), new CheckList([self::line('a', 'bad')]), $guide),
            $this->section('quiet', rows: 0),
        );

        static::assertSame(['table recording_disabled', 'section own', 'section other'], self::shape($this->builder(recordingEnabled: false)->create($screen, null, null, null)));
        static::assertSame(['table buffer_unavailable', 'section own', 'section other'], self::shape($this->builder(bufferDirectory: new BufferDirectory($file, 'test'))->create($screen, null, null, null)));
        static::assertSame(['table no_connection', 'section own'], self::shape($this->builder(null)->create($screen, null, null, null)));
        static::assertSame(['table storage_error', 'section own'], self::shape($this->builder()->create($screen, null, null, null, true)));
    }

    public function testTheRaisedChecksAreReadOnceAndBeforeTheSections(): void
    {
        $reader = new class implements ReaderInterface {
            /** @var list<string> */
            public array $calls = [];

            public function read(ReportQuery $query): Report
            {
                $this->calls[] = 'read';

                return new Report();
            }

            public function labels(array $keys): array
            {
                return [];
            }

            public function totals(Period $period, int $now, array $metricTypes, array $keyed = []): Totals
            {
                $this->calls[] = 'totals ' . $period->label();

                return new Totals([self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]);
            }

            private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';
        };
        $first = $this->briefed('first', new Brief('1', '1 first'));
        $second = $this->section('second', rows: 1);
        $builder = new ScreenBuilder($reader, true, new MockClock('@1800000000'), checks: new ApplicationChecks([new ServerErrorsCheck()]));

        $page = $builder->create(self::composed($first, $second), '6h', null, null);

        static::assertSame(['totals 6h', 'read', 'read'], $reader->calls);
        static::assertNotNull($page->raised);
        static::assertTrue($page->raised->has('requests.server_errors'));
        static::assertSame([$page->raised], $first->raised);
        static::assertSame([$page->raised], $second->raised);
        static::assertSame($page->raised, $page->withTabs(new SubNav('Application', []))->raised);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function readFailures(): iterable
    {
        yield 'exception' => [new \RuntimeException('Storage is down')];
        yield 'error' => [new \TypeError('Storage is down')];
    }

    #[DataProvider('readFailures')]
    public function testATotalsReadThatThrowsStopsTheSections(\Throwable $failure): void
    {
        $reader = new FixedReader(totals: []);
        $failing = new class ($reader, $failure) implements ReaderInterface {
            public function __construct(
                public readonly FixedReader $reader,
                private readonly \Throwable $failure,
            ) {}

            public function read(ReportQuery $query): Report
            {
                return $this->reader->read($query);
            }

            public function labels(array $keys): array
            {
                return [];
            }

            public function totals(Period $period, int $now, array $metricTypes, array $keyed = []): Totals
            {
                throw $this->failure;
            }
        };
        $logger = $this->logger();
        $notice = Block::notice(new Notice('info', 'Own'));
        $first = $this->briefed('first', new Brief('1', '1 first'));
        $own = new StubSourceSection($this->briefed('own', new Brief('2', '2 own')), [$notice]);

        $page = (new ScreenBuilder($failing, true, new MockClock('@1800000000'), logger: $logger, checks: new ApplicationChecks([new ServerErrorsCheck()])))->create(self::composed($first, $own), null, null, null);

        static::assertSame([], $reader->queries);
        static::assertSame([], $first->builds);
        static::assertSame([], $own->section->builds);
        static::assertSame(['table storage_error', 'section own'], self::shape($page));
        static::assertSame([$notice], self::sectionOf($page, 'own')->blocks);
        static::assertNull($page->raised);
        static::assertSame(['error JMonitor could not read its data: Storage is down'], $logger->messages);
    }

    #[DataProvider('readFailures')]
    public function testASectionReadThatThrowsLeavesThePageWithoutRaisedChecks(\Throwable $failure): void
    {
        $reader = new FixedReader(static fn(): Report => throw $failure, totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]);
        $logger = $this->logger();
        $second = $this->briefed('second', new Brief('1', '1 second'));
        $builder = new ScreenBuilder($reader, true, new MockClock('@1800000000'), logger: $logger, checks: new ApplicationChecks([new ServerErrorsCheck()]));

        $page = $builder->create(self::composed($this->briefed('first', new Brief('1', '1 first')), $second), null, null, null);

        static::assertCount(1, $reader->totalRequests);
        static::assertCount(1, $reader->queries);
        static::assertSame([], $second->builds);
        static::assertSame(['table storage_error'], self::shape($page));
        static::assertNull($page->raised);
        static::assertSame(['error JMonitor could not read its data: Storage is down'], $logger->messages);
    }

    public function testASectionShownWithoutBriefBringsItsGuideButNoCheck(): void
    {
        $unbriefed = new StubSection('unbriefed', fn(): ScreenView => new ScreenView([new Card('Runs', '3')], [$this->table(rows: 1)]), checks: new CheckList([self::line('unbriefed.bad', 'bad')], emptyText: 'no unbriefed problem'), guide: new Guide([new GuideItem('Unbriefed', 'Text')]));

        foreach ([
            'with a line' => new CheckList([self::line('cache.low_hit_rate', 'warn')], emptyText: 'no pool with a low hit rate'),
            'without line' => new CheckList([], emptyText: 'no pool with a low hit rate'),
        ] as $case => $checks) {
            $page = $this->builder()->create(self::composed($unbriefed, $this->briefed('cache', new Brief('9', '9 reads'), $checks)), null, null, null);
            $list = $page->blocks[1]->content;

            static::assertSame(['lead ready', 'checks ready', 'section unbriefed', 'section cache', 'guide ready'], self::shape($page), $case);
            $guide = $page->blocks[4]->content;
            static::assertInstanceOf(Guide::class, $guide);
            static::assertSame(['Unbriefed'], array_map(static fn(GuideItem $item): string => $item->term, $guide->items), $case);
            static::assertInstanceOf(CheckList::class, $list);
            static::assertSame(array_map(static fn(CheckLine $line): string => $line->id, $checks->lines), array_map(static fn(CheckLine $line): string => $line->id, $list->lines), $case);
            static::assertSame('No pool with a low hit rate in the last hour.', $list->emptyText, $case);
        }
    }

    public function testWithoutReaderOrAfterAFailedFlushTheTotalsAreNotRead(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]);
        $checks = new ApplicationChecks([new ServerErrorsCheck()]);
        $screen = self::composed($this->briefed('first', new Brief('1', '1 first')));

        $page = (new ScreenBuilder($reader, true, new MockClock('@1800000000'), checks: $checks))->create($screen, null, null, null, true);

        static::assertSame([], $reader->totalRequests);
        static::assertNull($page->raised);
        static::assertNull((new ScreenBuilder(null, true, new MockClock('@1800000000'), checks: $checks))->create($screen, null, null, null)->raised);
    }

    public function testOwnBlocksThatThrowLeaveTheSentence(): void
    {
        $logger = $this->logger();
        $queues = new StubSourceSection($this->briefed('messages', new Brief('4', '4 messages handled')), new \RuntimeException('Queues failed'));

        $page = (new ScreenBuilder($this->reader(), true, new MockClock('@1800000000'), logger: $logger))->create(self::composed($queues, $this->briefed('tasks', new Brief('2', '2 scheduled runs'))), null, null, null);

        static::assertSame(['lead ready', 'checks ready', 'section messages', 'section tasks'], self::shape($page));
        static::assertEquals(new Lead('4 messages handled and 2 scheduled runs in the last hour.'), $page->blocks[0]->content);
        static::assertEquals(Block::unavailable(Block::TABLE, BlockState::Error), self::sectionOf($page, 'messages')->blocks[0]);
        static::assertSame([Block::TABLE, Block::CARDS, Block::TABLE], self::types(self::sectionOf($page, 'messages')->blocks));
        static::assertSame(['error JMonitor could not display the "messages" section: Queues failed'], $logger->messages);
    }

    private static function composed(SectionInterface ...$sections): ComposedScreen
    {
        return new ComposedScreen('background', 'Background', 'Work done in the background', Space::Application, $sections);
    }

    /**
     * @return list<string> each block of the page, by its section or by its type and state
     */
    private static function shape(Page $page): array
    {
        return array_map(static fn(Block $block): string => $block->content instanceof Section ? 'section ' . $block->content->id : $block->type . ' ' . $block->state->value, $page->blocks);
    }

    private static function sectionOf(Page $page, string $id): Section
    {
        foreach ($page->blocks as $block) {
            if ($block->type === Block::SECTION && $block->content instanceof Section && $block->content->id === $id) {
                return $block->content;
            }
        }

        static::fail(sprintf('The page has no "%s" section.', $id));
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<string>
     */
    private static function types(array $blocks): array
    {
        return array_map(static fn(Block $block): string => $block->type, $blocks);
    }

    private static function line(string $id, string $tone): CheckLine
    {
        return new CheckLine($id, new Mark($tone, $tone === 'bad' ? 'Critical' : 'Warning'), 'Sentence of ' . $id, 'Why.', '', title: 'Title of ' . $id, action: 'What.');
    }

    /** @param non-empty-string $id */
    private function briefed(string $id, Brief $brief, ?CheckList $checks = null, ?Guide $guide = null): StubSection
    {
        $table = $this->table(1);

        return new StubSection($id, static fn(): ScreenView => new ScreenView([new Card('Runs', '3')], [$table]), brief: $brief, checks: $checks, guide: $guide);
    }

    /**
     * @param non-empty-string                      $id
     * @param non-empty-array<string, 'asc'|'desc'> $sorts
     */
    private function section(string $id, int $rows, bool $available = true, array $sorts = ['count' => 'desc', 'name' => 'asc']): StubSection
    {
        $table = $this->table($rows);

        return new StubSection($id, static fn(): ScreenView => new ScreenView([new Card('Runs', '3')], [$table]), $available, $sorts);
    }

    public function testAMapTakesThePlaceOfTheCards(): void
    {
        $lead = new Lead('Sentence');
        $checks = new CheckList([]);
        $guide = new Guide([new GuideItem('Term', 'Text')]);
        $map = new Map([new MapBox('Requests', '12')]);
        $table = $this->table(rows: 1);
        $view = static fn(ScreenView $view): StubScreen => new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => $view);

        $page = $this->builder()->create($view(new ScreenView([new Card('Hits', '12')], [$table], $lead, $checks, $guide, map: $map)), null, null, null);

        static::assertSame([Block::LEAD, Block::CHECKS, Block::MAP, Block::TABLE, Block::GUIDE], self::types($page->blocks));
        static::assertSame($map, $page->blocks[2]->content);
        static::assertSame([Block::MAP, Block::TABLE], self::types($this->builder()->create($view(new ScreenView([], [$table], map: $map)), null, null, null)->blocks), 'A map stands in for the cards, even without a lead.');
    }

    public function testThePlacesCloseThePage(): void
    {
        $guide = new Guide([new GuideItem('Term', 'Text')]);
        $places = new Places([new Place('Overview', '', 'This page.', 'Is everything fine?')]);
        $map = new Map([new MapBox('Requests', '12')]);
        $table = $this->table(rows: 1);
        $view = static fn(ScreenView $view): StubScreen => new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => $view);

        $page = $this->builder()->create($view(new ScreenView([], [$table], new Lead('Sentence'), new CheckList([]), $guide, map: $map, places: $places)), null, null, null);

        static::assertSame([Block::LEAD, Block::CHECKS, Block::MAP, Block::TABLE, Block::GUIDE, Block::PLACES], self::types($page->blocks));
        static::assertSame($places, $page->blocks[5]->content);
        static::assertSame([Block::LEAD, Block::MAP, Block::TABLE, Block::PLACES], self::types($this->builder()->create($view(new ScreenView([], [$table], new Lead('Sentence'), map: $map, places: $places)), null, null, null)->blocks));
    }

    public function testTheStatusAndTheAlertsOpenThePageBeforeTheChecks(): void
    {
        $status = new Status('Overview', new Mark('ok', 'Answers normally'));
        $alerts = new Alerts([new Alert('requests.slow', new Mark('warn', 'Warning'), '3 slow requests', 'Requests', 'requests')]);
        $lead = new Lead('Sentence');
        $checks = new CheckList([]);
        $map = new Map([new MapBox('Requests', '12')]);
        $places = new Places([new Place('Overview', '', 'This page.', 'Is everything fine?')]);
        $table = $this->table(rows: 1);
        $view = static fn(ScreenView $view): StubScreen => new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => $view);

        $page = $this->builder()->create($view(new ScreenView([], [$table], checks: $checks, map: $map, places: $places, status: $status, alerts: $alerts)), null, null, null);

        static::assertSame([Block::STATUS, Block::ALERTS, Block::CHECKS, Block::MAP, Block::TABLE, Block::PLACES], self::types($page->blocks));
        static::assertSame([$status, $alerts], [$page->blocks[0]->content, $page->blocks[1]->content]);
        static::assertSame([Block::STATUS, Block::LEAD, Block::ALERTS, Block::TABLE], self::types($this->builder()->create($view(new ScreenView([], [$table], $lead, status: $status, alerts: $alerts)), null, null, null)->blocks), 'A status takes the place of an empty list of cards, as a lead does.');
        static::assertSame([Block::ALERTS, Block::CARDS, Block::TABLE], self::types($this->builder()->create($view(new ScreenView([], [$table], alerts: $alerts)), null, null, null)->blocks));
    }

    public function testAStatusAloneLeavesOutTheEmptyListOfCards(): void
    {
        $status = new Status('Overview', new Mark('ok', 'Answers normally'));
        $table = $this->table(rows: 1);
        $view = static fn(ScreenView $view): StubScreen => new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => $view);

        static::assertSame([Block::STATUS, Block::TABLE], self::types($this->builder()->create($view(new ScreenView([], [$table], status: $status)), null, null, null)->blocks));
        static::assertSame([Block::CARDS, Block::TABLE], self::types($this->builder()->create($view(new ScreenView([], [$table])), null, null, null)->blocks));
    }

    public function testTheOverviewReadsTheApplicationThenTheStack(): void
    {
        $journal = [];
        $report = new Report([self::requestRow()], null, 1, array_fill(0, 60, 0), self::requestRow()->metrics);
        $reader = new FixedReader(static function (ReportQuery $query) use (&$journal, $report): Report {
            $journal[] = 'read ' . $query->family->value;

            return $report;
        }, totals: $report->totals);
        $glance = new FixedGlance(static function () use (&$journal, $reader): StackGlance {
            $journal[] = 'stack after ' . \count($reader->totalRequests) . ' totals';

            return new StackGlance([new ComponentCard('php', 'PHP', new Mark('ok', 'Healthy'))]);
        });

        $page = $this->builder($reader)->create(self::overview($glance), '6h', 'total', 'asc');

        static::assertSame(['read request', 'stack after 1 totals'], $journal);
        static::assertSame([Block::STATUS, Block::MAP], self::types($page->blocks));
        static::assertSame(Period::SixHours, $page->period);
        static::assertSame('', $page->sort);
        static::assertSame([], $page->sorts);
        static::assertSame(1, $glance->calls);
        $map = $page->blocks[1]->content;
        static::assertInstanceOf(Map::class, $map);
        static::assertNotNull($map->runsOn);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function overviewFailures(): iterable
    {
        yield 'exception' => [new \RuntimeException('Storage is down')];
        yield 'error' => [new \TypeError('Storage is down')];
    }

    #[DataProvider('overviewFailures')]
    public function testAStorageReadThatThrowsKeepsTheStackOfTheOverview(\Throwable $failure): void
    {
        $reader = new FixedReader(static fn(): Report => throw $failure);
        $logger = $this->logger();
        $alert = new Alert('php.memory', new Mark('warn', 'Warning'), 'Memory is nearly full', 'PHP', 'stack/php');
        $glance = new FixedGlance(new StackGlance([new ComponentCard('php', 'PHP', new Mark('warn', 'Warning'))], [$alert]));

        $page = (new ScreenBuilder($reader, true, new MockClock('@1800000000'), logger: $logger))->create(self::overview($glance), null, null, null);

        static::assertSame(['status ready', 'table storage_error', 'alerts ready', 'map ready', 'places ready'], self::shape($page));
        static::assertSame(1, $glance->calls);
        static::assertNull($page->raised);
        $alerts = $page->blocks[2]->content;
        static::assertInstanceOf(Alerts::class, $alerts);
        static::assertSame([$alert], $alerts->alerts);
        static::assertSame(['error JMonitor could not read its data: Storage is down'], $logger->messages);
    }

    public function testWithoutConnectionOrAfterAFailedFlushTheOverviewStillShowsTheStack(): void
    {
        $glance = new FixedGlance(new StackGlance([new ComponentCard('php', 'PHP', new Mark('ok', 'Healthy'))]));

        static::assertSame(['status ready', 'table no_connection', 'map ready', 'places ready'], self::shape($this->builder(null)->create(self::overview($glance), null, null, null)));
        static::assertSame(['status ready', 'table storage_error', 'map ready', 'places ready'], self::shape($this->builder()->create(self::overview($glance), null, null, null, true)));
        static::assertSame(['status ready', 'table storage_error', 'places ready'], self::shape($this->builder()->create(self::overview(null), null, null, null, true)));
    }

    public function testAStackThatThrowsKeepsTheApplicationOfTheOverview(): void
    {
        $report = new Report([self::requestRow()], null, 1, array_fill(0, 60, 0), self::requestRow()->metrics);
        $logger = $this->logger();
        $glance = new FixedGlance(static fn(): StackGlance => throw new \LogicException('No reader of the live stack.'));

        $page = (new ScreenBuilder(new FixedReader($report, totals: $report->totals), true, new MockClock('@1800000000'), logger: $logger))->create(self::overview($glance), null, null, null);

        static::assertSame([Block::STATUS, Block::MAP], self::types($page->blocks));
        $map = $page->blocks[1]->content;
        static::assertInstanceOf(Map::class, $map);
        static::assertNull($map->runsOn);
        static::assertSame(['Requests'], array_map(static fn(MapBox $box): string => $box->label, $map->incoming));
        static::assertSame(['error JMonitor could not read the stack: No reader of the live stack.'], $logger->messages);
    }

    public function testMessagesWithoutRequestShowTheMapOfTheOverviewWithoutTable(): void
    {
        $reader = new FixedReader(new Report(), totals: [Metric::MESSAGE_DURATION => new MetricValue(3, 30.0, 12.0), Metric::MESSAGE_SENT => new MetricValue(3, 0.0, 0.0)]);
        $checks = new ApplicationChecks([new ServerErrorsCheck()]);
        $screen = new OverviewScreen($checks, [new MessagesSection(true, true, null, $checks)], [], null);

        $page = $this->builder($reader)->create($screen, null, null, null);

        static::assertSame([Block::STATUS, Block::MAP], self::types($page->blocks));
        $map = $page->blocks[1]->content;
        static::assertInstanceOf(Map::class, $map);
        static::assertEquals(
            [new MapBox('Requests', '0', path: 'requests'), new MapBox('Messages', '3', path: 'background', anchor: 'messages')],
            $map->incoming,
        );
    }

    public function testAnErrorOfTheStackKeepsTheApplicationOfTheOverview(): void
    {
        $report = new Report([self::requestRow()], null, 1, array_fill(0, 60, 0), self::requestRow()->metrics);
        $logger = $this->logger();
        $glance = new FixedGlance(static fn(): StackGlance => throw new \Error('Call to a member function on null'));

        $page = (new ScreenBuilder(new FixedReader($report, totals: $report->totals), true, new MockClock('@1800000000'), logger: $logger))->create(self::overview($glance), null, null, null);

        static::assertSame([Block::STATUS, Block::MAP], self::types($page->blocks));
        static::assertSame(['error JMonitor could not read the stack: Call to a member function on null'], $logger->messages);
    }

    public function testAViewThatThrowsLeavesTheErrorStateOfTheOverview(): void
    {
        $logger = $this->logger();
        $broken = new class implements SectionInterface {
            public function id(): string
            {
                return 'commands';
            }

            public function title(): string
            {
                return 'Commands';
            }

            public function summary(): string
            {
                return '';
            }

            public function isAvailable(): bool
            {
                throw new \LogicException('Broken section');
            }

            public function sorts(): array
            {
                return ['runs' => 'desc'];
            }

            public function emptyState(Period $period): array
            {
                return ['title' => '', 'text' => ''];
            }

            public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction, RaisedChecks $raised): ScreenView
            {
                return new ScreenView();
            }
        };
        $screen = new OverviewScreen(new ApplicationChecks([new ServerErrorsCheck()]), [$broken], [], new FixedGlance());
        $reader = new FixedReader(new Report([self::requestRow()], null, 1, [], self::requestRow()->metrics), totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)] + self::requestRow()->metrics);

        $page = (new ScreenBuilder($reader, true, new MockClock('@1800000000'), logger: $logger))->create($screen, null, null, null);

        static::assertSame(['status ready', 'table error'], self::shape($page));
        static::assertEquals(new Status('Overview'), $page->blocks[0]->content);
        static::assertSame(['error JMonitor could not display the "overview" screen: Broken section'], $logger->messages);
        static::assertNotNull($page->raised);
        static::assertTrue($page->raised->has('requests.server_errors'));
    }

    public function testAViewThatThrowsAfterACauseKeepsTheStatusThenTheCauseThenTheError(): void
    {
        $logger = $this->logger();
        $glance = new FixedGlance(new StackGlance(alerts: [new \stdClass()]));

        $page = (new ScreenBuilder(null, true, new MockClock('@1800000000'), logger: $logger))->create(self::overview($glance), null, null, null);

        static::assertSame(['status ready', 'table no_connection', 'table error'], self::shape($page));
        static::assertEquals(new Status('Overview'), $page->blocks[0]->content);
        static::assertCount(1, $logger->messages);
        static::assertStringStartsWith('error JMonitor could not display the "overview" screen: ', $logger->messages[0]);
    }

    public function testTheOverviewHandsItsRaisedChecksToThePage(): void
    {
        $reader = new FixedReader(new Report([self::requestRow()]), totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]);

        $page = $this->builder($reader)->create(self::overview(null), null, null, null);

        static::assertNotNull($page->raised);
        static::assertTrue($page->raised->has('requests.server_errors'));
        static::assertCount(1, $reader->totalRequests);
    }

    public function testWithoutConnectionTheOverviewSaysItOnceThenShowsTheStack(): void
    {
        $this->assertTheOverviewTellsItsCauseOnce($this->builder(null), false, 'no_connection');
    }

    public function testAFailedFlushIsToldOnceOnTheOverviewThenTheStack(): void
    {
        $this->assertTheOverviewTellsItsCauseOnce($this->builder(), true, 'storage_error');
    }

    public function testAReadThatThrowsIsToldOnceOnTheOverviewThenTheStack(): void
    {
        $this->assertTheOverviewTellsItsCauseOnce($this->builder(new FixedReader(static fn(): Report => throw new \RuntimeException('Storage is down'))), false, 'storage_error');
    }

    public function testRecordingOffWithoutDataIsToldOnceOnTheOverviewThenTheStack(): void
    {
        $this->assertTheOverviewTellsItsCauseOnce($this->builder(recordingEnabled: false), false, 'recording_disabled');
    }

    public function testAnUnusableBufferDirectoryWithoutDataIsToldOnceOnTheOverviewThenTheStack(): void
    {
        $file = $this->path();
        touch($file);

        $this->assertTheOverviewTellsItsCauseOnce($this->builder(bufferDirectory: new BufferDirectory($file, 'test')), false, 'buffer_unavailable');
    }

    public function testRecordingOffWithDataInThePeriodShowsTheOverview(): void
    {
        $file = $this->path();
        touch($file);
        $report = new Report([self::requestRow()], null, 1, array_fill(0, 60, 0), self::requestRow()->metrics);
        $glance = new FixedGlance(new StackGlance([new ComponentCard('php', 'PHP', new Mark('ok', 'Healthy'))]));

        foreach ([
            'recording off' => $this->builder(new FixedReader($report, totals: $report->totals), false),
            'buffer directory unusable' => $this->builder(new FixedReader($report, totals: $report->totals), bufferDirectory: new BufferDirectory($file, 'test')),
        ] as $case => $builder) {
            $page = $builder->create(self::overview($glance), null, null, null);

            static::assertSame(['status ready', 'map ready'], self::shape($page), $case);
            static::assertSame('ready', $page->state(), $case);
        }
    }

    public function testServerErrorsCountedWithoutRequestsHideNoCauseOfAnEmptyPeriod(): void
    {
        $file = $this->path();
        touch($file);
        $reader = static fn(): FixedReader => new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(2, 0.0, 0.0)]);

        foreach ([
            'recording off' => $this->builder($reader(), false),
            'buffer directory unusable' => $this->builder($reader(), bufferDirectory: new BufferDirectory($file, 'test')),
        ] as $case => $builder) {
            $page = $builder->create(self::overview(null), null, null, null);

            static::assertSame(['status ready', 'alerts ready', 'map ready'], self::shape($page), $case);
            static::assertEquals(new Status('Overview', new Mark('bad', '2 server errors')), $page->blocks[0]->content, $case);
            $alerts = $page->blocks[1]->content;
            static::assertInstanceOf(Alerts::class, $alerts);
            static::assertSame(['requests.server_errors'], array_map(static fn(Alert $alert): string => $alert->id, $alerts->alerts), $case);
        }
    }

    public function testWithoutStackACauseLeavesTheStateAndThePlaces(): void
    {
        static::assertSame(['status ready', 'table no_connection', 'places ready'], self::shape($this->builder(null)->create(self::overview(null), null, null, null)));
        static::assertSame(['status ready', 'table recording_disabled', 'places ready'], self::shape($this->builder(recordingEnabled: false)->create(self::overview(null), null, null, null)));
        static::assertSame(['status ready', 'table storage_error', 'places ready'], self::shape($this->builder(new FixedReader(static fn(): Report => throw new \RuntimeException('Storage is down')))->create(self::overview(null), null, null, null)));
    }

    public function testNothingInThePeriodWithoutCauseShowsTheFirstRunOfTheOverview(): void
    {
        $glance = new FixedGlance(new StackGlance([new ComponentCard('php', 'PHP', new Mark('ok', 'Healthy'))]));

        $page = $this->builder()->create(self::overview($glance), null, null, null);

        static::assertSame(['status ready', 'map ready', 'places ready'], self::shape($page));
        static::assertNotNull($page->raised);
        static::assertSame(['status ready', 'map ready', 'places ready'], self::shape($this->builder()->create(self::overview(null), null, null, null)));
    }

    private function assertTheOverviewTellsItsCauseOnce(ScreenBuilder $builder, bool $storageFailed, string $state): void
    {
        $alert = new Alert('php.memory', new Mark('warn', 'Warning'), 'Memory is nearly full', 'PHP', 'stack/php');
        $stack = new StackGlance([new ComponentCard('php', 'PHP', new Mark('warn', 'Warning'))], [$alert]);
        $glance = new FixedGlance($stack);

        $page = $builder->create(self::overview($glance), null, null, null, $storageFailed);

        static::assertSame(['status ready', 'table ' . $state, 'alerts ready', 'map ready', 'places ready'], self::shape($page));
        static::assertSame(1, $glance->calls);
        static::assertEquals(new Status('Overview'), $page->blocks[0]->content);
        $alerts = $page->blocks[2]->content;
        static::assertInstanceOf(Alerts::class, $alerts);
        static::assertSame([$alert], $alerts->alerts);
        static::assertEquals(new Map(runsOn: $stack), $page->blocks[3]->content);
        $places = $page->blocks[4]->content;
        static::assertInstanceOf(Places::class, $places);
        static::assertSame(['Overview', 'Application', 'Stack'], array_map(static fn(Place $place): string => $place->label, $places->places));
    }

    private static function overview(?FixedGlance $glance): OverviewScreen
    {
        return new OverviewScreen(new ApplicationChecks([new ServerErrorsCheck()]), [], [], $glance);
    }

    private static function requestRow(): ReportRow
    {
        return new ReportRow(md5('GET app_a'), 'GET app_a', 'GET app_a', [Metric::REQUEST_DURATION => new MetricValue(2, 20.0, 12.0)]);
    }

    private function builder(?ReaderInterface $reader = new FixedReader(), bool $recordingEnabled = true, ?BufferDirectory $bufferDirectory = null): ScreenBuilder
    {
        return new ScreenBuilder($reader, $recordingEnabled, new MockClock('@1800000000'), $bufferDirectory);
    }

    private function screen(int $rows): StubScreen
    {
        $table = $this->table($rows);

        return new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => new ScreenView([], [$table]));
    }

    private function table(int $rows): Table
    {
        return new Table('stubs', 'Stubs', [new Column('name', 'Name', false, 'name')], array_map(
            static fn(int $i): Row => new Row('k' . $i, ['name' => new Cell('stub ' . $i)]),
            $rows === 0 ? [] : range(1, $rows),
        ));
    }

    private function reader(): ReaderInterface
    {
        return new FixedReader();
    }

    /** @return AbstractLogger&object{messages: list<string>} */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = $level . ' ' . $message;
            }
        };
    }

    private function path(): string
    {
        return $this->paths[] = sys_get_temp_dir() . '/jmonitor-builder-' . bin2hex(random_bytes(6));
    }
}
