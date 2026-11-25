<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\Page;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Section;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class ScreenBuilderTest extends TestCase
{
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

    public function testStorageIsUsableWithAReaderAndRecordingOn(): void
    {
        static::assertTrue($this->builder($this->reader(), true)->storageUsable());
        static::assertFalse($this->builder($this->reader(), false)->storageUsable());
        static::assertFalse($this->builder(null, true)->storageUsable());
        static::assertFalse($this->builder(null, false)->storageUsable());
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
        static::assertSame('ready', $page->state());
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

    private static function composed(SectionInterface ...$sections): ComposedScreen
    {
        return new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, $sections);
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

    /**
     * @param non-empty-string                      $id
     * @param non-empty-array<string, 'asc'|'desc'> $sorts
     */
    private function section(string $id, int $rows, bool $available = true, array $sorts = ['count' => 'desc', 'name' => 'asc']): StubSection
    {
        $table = $this->table($rows);

        return new StubSection($id, static fn(): ScreenView => new ScreenView([new Card('Runs', '3')], [$table]), $available, $sorts);
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
