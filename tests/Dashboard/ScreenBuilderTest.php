<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Dashboard\Page;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenState;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
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

        static::assertSame(ScreenState::StorageError, $this->builder($reader)->create($this->screen(rows: 1), null, null, null, true)->state);
        static::assertSame(ScreenState::NoConnection, $this->builder(null)->create($this->screen(rows: 1), null, null, null)->state);
        static::assertSame(ScreenState::RecordingDisabled, $this->builder($reader, false)->create($this->screen(rows: 0), null, null, null)->state);
        static::assertSame(ScreenState::Ready, $this->builder($reader, false)->create($this->screen(rows: 1), null, null, null)->state);
        static::assertSame(ScreenState::Empty, $this->builder($reader)->create($this->screen(rows: 0), null, null, null)->state);

        $ready = $this->builder($reader)->create($this->screen(rows: 2), '6h', null, null);
        static::assertSame(ScreenState::Ready, $ready->state);
        static::assertSame(Period::SixHours, $ready->period);
        static::assertCount(2, $ready->view->tables[0]->rows);
    }

    public function testAFailingReadIsLoggedAndShownAsAStorageError(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = $level . ' ' . $message;
            }
        };
        $screen = new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => throw new \RuntimeException('Storage is down'));

        $page = (new ScreenBuilder($this->reader(), true, new MockClock('@1800000000'), logger: $logger))->create($screen, null, null, null);

        static::assertSame(ScreenState::StorageError, $page->state);
        static::assertEquals(new ScreenView(), $page->view);
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

        static::assertSame(ScreenState::BufferUnavailable, $this->builder(bufferDirectory: $unusable)->create($this->screen(rows: 0), null, null, null)->state);
        static::assertSame(ScreenState::Ready, $this->builder(bufferDirectory: $unusable)->create($this->screen(rows: 1), null, null, null)->state);
        static::assertSame(ScreenState::RecordingDisabled, $this->builder(recordingEnabled: false, bufferDirectory: $unusable)->create($this->screen(rows: 0), null, null, null)->state);
        static::assertSame(ScreenState::Empty, $this->builder(bufferDirectory: $usable)->create($this->screen(rows: 0), null, null, null)->state);
    }

    public function testThePageCarriesTheScreenAndItsView(): void
    {
        $screen = $this->screen(rows: 1);
        $page = $this->builder()->create($screen, null, null, null);

        static::assertInstanceOf(Page::class, $page);
        static::assertSame($screen, $page->screen);
        static::assertFalse($page->view->isEmpty());
        static::assertTrue((new ScreenView())->isEmpty());
        static::assertTrue((new ScreenView(tables: [new Table('t', 'T', [], [])]))->isEmpty());
    }

    private function builder(?ReaderInterface $reader = new FixedReader(), bool $recordingEnabled = true, ?BufferDirectory $bufferDirectory = null): ScreenBuilder
    {
        return new ScreenBuilder($reader, $recordingEnabled, new MockClock('@1800000000'), $bufferDirectory);
    }

    private function screen(int $rows): StubScreen
    {
        $table = new Table('stubs', 'Stubs', [new Column('name', 'Name', false, 'name')], array_map(
            static fn(int $i): Row => new Row('k' . $i, ['name' => new Cell('stub ' . $i)]),
            $rows === 0 ? [] : range(1, $rows),
        ));

        return new StubScreen(view: static fn(ReaderInterface $reader): ScreenView => new ScreenView([], [$table]));
    }

    private function reader(): ReaderInterface
    {
        return new FixedReader();
    }

    private function path(): string
    {
        return $this->paths[] = sys_get_temp_dir() . '/jmonitor-builder-' . bin2hex(random_bytes(6));
    }
}
