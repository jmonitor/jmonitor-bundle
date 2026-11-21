<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\Page;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
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

    public function testAScreenOfBothKindsShowsItsStorageBlocksFirst(): void
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

        static::assertSame([Block::CARDS, Block::TABLE, Block::CARDS], array_map(static fn(Block $block): string => $block->type, $page->blocks));
        static::assertSame($own, $page->blocks[2]);
        static::assertSame('mixed', $page->state());
        static::assertSame(Period::SixHours, $page->period);
        static::assertSame(['count', 'desc'], [$page->sort, $page->direction]);
    }

    public function testStorageIsUsableWithAReaderAndRecordingOn(): void
    {
        static::assertTrue($this->builder($this->reader(), true)->storageUsable());
        static::assertFalse($this->builder($this->reader(), false)->storageUsable());
        static::assertFalse($this->builder(null, true)->storageUsable());
        static::assertFalse($this->builder(null, false)->storageUsable());
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
