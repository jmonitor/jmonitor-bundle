<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\ComponentScreen;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ServerDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\SymfonyDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\FakeStackReader;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ComponentScreenTest extends TestCase
{
    private MockClock $clock;

    private CountingCachePool $pool;

    protected function setUp(): void
    {
        $this->clock = new MockClock('@' . Snapshots::NOW);
        $this->pool = new CountingCachePool();
    }

    public function testEachComponentIsAScreen(): void
    {
        $reader = new FakeStackReader(self::stack());
        $screens = [
            $this->screen($reader, Component::System),
            $this->screen($reader, Component::Php),
            $this->screen($reader, Component::Symfony),
        ];

        self::assertSame(['system', 'php', 'symfony'], array_map(static fn(ComponentScreen $screen): string => $screen->id(), $screens));
        self::assertSame(['Server', 'PHP', 'Symfony'], array_map(static fn(ComponentScreen $screen): string => $screen->label(), $screens));
        self::assertSame([
            'The server answering this page, read live',
            'The PHP runtime of this web server, read live',
            'The Symfony application, read live',
        ], array_map(static fn(ComponentScreen $screen): string => $screen->heading(), $screens));

        foreach ($screens as $screen) {
            self::assertTrue($screen->isAvailable());
            self::assertSame(['refresh' => 'Refresh'], $screen->actions());
        }
        self::assertSame([], $reader->calls);
    }

    public function testTheDetailShowsTheNavigationTheCardTheChecksThenTheSections(): void
    {
        $php = Snapshots::php();
        $php['display_errors'] = '1';
        $state = self::stack(Snapshots::of(Component::Php, ['php' => $php]));

        $blocks = $this->screen(new FakeStackReader($state), Component::Php)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame(
            [Block::SUBNAV, Block::COMPONENTS, Block::CHECKS, Block::FACTS, Block::GAUGES, Block::GAUGES, Block::FACTS],
            array_map(static fn(Block $block): string => $block->type, $blocks),
        );

        $cards = $blocks[1]->content;
        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertCount(1, $cards->cards);
        self::assertSame('php', $cards->cards[0]->id);
        self::assertNull($cards->cards[0]->path);
        self::assertEquals(new Mark('warn', 'Warning'), $cards->cards[0]->mark);
        self::assertSame([], $cards->notDetected);
        self::assertNull($cards->more);

        $checks = $blocks[2]->content;
        self::assertInstanceOf(CheckList::class, $checks);
        self::assertSame(
            [['php.display_errors', 'Warning', 'PHP', null], ['php.fpm_max_children', 'Info', 'PHP', null]],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->mark->label, $line->where, $line->path], $checks->lines),
        );

        $runtime = $blocks[3]->content;
        self::assertInstanceOf(Facts::class, $runtime);
        self::assertSame('Runtime', $runtime->title);
        self::assertEquals(new Mark('warn', 'Warning'), $runtime->facts[4]->mark);
        self::assertSame([false, false, false, false, false, false, false], array_map(static fn(Block $block): bool => $block->reading, $blocks));
    }

    public function testNothingToCheckIsSaidForTheComponent(): void
    {
        $blocks = $this->screen(new FakeStackReader(self::stack(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()]))), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new CheckList([], 'Nothing to check on Symfony'), $blocks[2]->content);
    }

    public function testTheDetailOnlyReadsItsComponent(): void
    {
        $reader = new FakeStackReader(self::stack(Snapshots::of(Component::Php, ['php' => Snapshots::php()])));
        $screen = $this->screen($reader, Component::Php);

        $screen->blocks(new ScreenRequest(Snapshots::NOW));
        $screen->blocks(new ScreenRequest(Snapshots::NOW, true));

        self::assertSame(['component php Page', 'component php Fragment'], $reader->calls);
    }

    public function testTheDetailDoesNotWriteTheSummary(): void
    {
        $this->screen(new FakeStackReader(self::stack(Snapshots::of(Component::Php, ['php' => Snapshots::php()]))), Component::Php)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame(['getItem' => 1], $this->pool->calls);
        self::assertNull($this->summary()->last());
    }

    public function testTheSecondNavigationComesFromTheLastVisit(): void
    {
        $this->remember(['system' => 'warning', 'php' => 'healthy', 'mysql' => 'critical', 'symfony' => 'flaky']);
        $php = Snapshots::php();
        $php['display_errors'] = '1';
        $state = self::stack(Snapshots::of(Component::Php, ['php' => $php]));

        $blocks = $this->screen(new FakeStackReader($state), Component::Php)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new SubNav([
            new SubNavItem('Overview', 'stack'),
            new SubNavItem('Server', 'stack/system', false, new Mark('warn', 'Warning')),
            new SubNavItem('PHP', 'stack/php', true, new Mark('warn', 'Warning')),
        ], 'web-1'), $blocks[0]->content);

        $this->remember(['symfony' => 'critical', 'system' => 'healthy']);
        $blocks = $this->screen(new FakeStackReader($state), Component::Php)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new SubNav([
            new SubNavItem('Overview', 'stack'),
            new SubNavItem('Server', 'stack/system', false, new Mark('ok', 'Healthy')),
            new SubNavItem('PHP', 'stack/php', true, new Mark('warn', 'Warning')),
            new SubNavItem('Symfony', 'stack/symfony', false, new Mark('bad', 'Critical')),
        ], 'web-1'), $blocks[0]->content);
    }

    public function testWithoutLastVisitTheSecondNavigationOnlyHasTheComponent(): void
    {
        $blocks = $this->screen(new FakeStackReader(self::stack(Snapshots::of(Component::System, ['system' => Snapshots::system()]))), Component::System)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new SubNav([
            new SubNavItem('Overview', 'stack'),
            new SubNavItem('Server', 'stack/system', true, new Mark('ok', 'Healthy')),
        ], 'web-1'), $blocks[0]->content);
    }

    public function testAComponentThatIsNotPartOfTheStackSaysSo(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], ['symfony' => 'No kernel was found']);

        $blocks = $this->screen(new FakeStackReader($state), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new SubNav([
            new SubNavItem('Overview', 'stack'),
            new SubNavItem('Symfony', 'stack/symfony', true, new Mark('unknown', 'Unknown')),
        ], 'web-1'), $blocks[0]->content);
        self::assertEquals(new Notice('info', 'Symfony is not part of this stack', 'No kernel was found'), $blocks[1]->content);

        $off = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], []);
        $blocks = $this->screen(new FakeStackReader($off), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new Notice('info', 'Symfony is not part of this stack', 'It is turned off, or was not found on this server.'), $blocks[1]->content);
        self::assertCount(2, $blocks);
    }

    public function testAnEngineFailureIsToldAndNothingElse(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], [], 'Cannot reach redis://cache:6379: Connection refused');

        $blocks = $this->screen(new FakeStackReader($state), Component::Php)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new Notice('bad', 'The stack could not be read', 'Cannot reach redis://cache:6379: Connection refused'), $blocks[1]->content);
    }

    public function testACacheOutOfOrderIsToldAndNothingKeepsReading(): void
    {
        $snapshot = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]);
        $state = new StackState('web-1', Snapshots::NOW, CacheState::OutOfOrder, Snapshots::stack($snapshot)->components, []);

        $blocks = $this->screen(new FakeStackReader($state), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE, Block::COMPONENTS, Block::CHECKS, Block::FACTS, Block::TABLE], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new Notice('warn', 'The live stack needs a working cache', 'Only what PHP can read in its own process is shown. Check the cache.app pool of the application.'), $blocks[1]->content);
        self::assertSame([false, false, false, false, false, false], array_map(static fn(Block $block): bool => $block->reading, $blocks));
        self::assertInstanceOf(ComponentCards::class, $blocks[2]->content);
        self::assertFalse($blocks[2]->content->cards[0]->reading);
    }

    public function testWhatIsStillReadingMarksTheCardAndTheSection(): void
    {
        $snapshot = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]);

        $blocks = $this->screen(new FakeStackReader(self::stack($snapshot)), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::COMPONENTS, Block::CHECKS, Block::FACTS, Block::TABLE, Block::FACTS], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertSame([false, true, false, false, false, true], array_map(static fn(Block $block): bool => $block->reading, $blocks));
        self::assertSame(BlockState::Reading, $blocks[5]->state);
    }

    public function testAnOutdatedValueIsReadAgainUnlessItsRenewalFailed(): void
    {
        $flex = ['up_to_date' => true, 'outdated_recipes' => []];
        $outdated = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => $flex], ['symfony.flex' => ResultState::Stale]);

        $blocks = $this->screen(new FakeStackReader(self::stack($outdated)), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame(Block::COMPONENTS, $blocks[1]->type);
        self::assertSame([1], array_keys(array_filter(array_map(static fn(Block $block): bool => $block->reading, $blocks))));
        self::assertInstanceOf(ComponentCards::class, $blocks[1]->content);
        self::assertFalse($blocks[1]->content->cards[0]->reading);

        $failed = new ComponentSnapshot(Component::Symfony, [
            ...$outdated->results,
            'symfony.flex' => new CollectorResult('symfony.flex', $flex, ResultState::Stale, 'composer could not run', Snapshots::NOW - Snapshots::STALE_AGE, expiresAt: Snapshots::NOW + 60),
        ]);

        $blocks = $this->screen(new FakeStackReader(self::stack($failed)), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertNotContains(true, array_map(static fn(Block $block): bool => $block->reading, $blocks));

        $outOfOrder = new StackState('web-1', Snapshots::NOW, CacheState::OutOfOrder, Snapshots::stack($outdated)->components, []);

        $blocks = $this->screen(new FakeStackReader($outOfOrder), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertNotContains(true, array_map(static fn(Block $block): bool => $block->reading, $blocks));

        $fresh = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => $flex]);

        $blocks = $this->screen(new FakeStackReader(self::stack($fresh)), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertNotContains(true, array_map(static fn(Block $block): bool => $block->reading, $blocks));
    }

    public function testRefreshOnlyForgetsItsComponent(): void
    {
        $reader = new FakeStackReader(self::stack());

        $this->screen($reader, Component::Symfony)->run('refresh');

        self::assertSame(['refresh symfony'], $reader->calls);
    }

    private static function stack(ComponentSnapshot ...$snapshots): StackState
    {
        $state = $snapshots === [] ? Snapshots::state([]) : Snapshots::stack(...$snapshots);

        return new StackState('web-1', $state->readAt, $state->cache, $state->components, $state->notDetected, $state->error);
    }

    /**
     * @param array<string, string> $components
     */
    private function remember(array $components): void
    {
        $this->pool->save($this->pool->getItem('jmonitor.stack.summary.' . (new WebNode('web-1'))->keyPrefix() . 'last')->set([
            'at' => Snapshots::NOW - 60,
            'critical' => 1,
            'warning' => 1,
            'components' => $components,
        ]));
        $this->pool->reset();
    }

    private function summary(): StackSummary
    {
        return new StackSummary(new ServiceLocator(['cache' => fn(): CountingCachePool => $this->pool]), new WebNode('web-1'), $this->clock);
    }

    private function screen(StackReaderInterface $reader, Component $component): ComponentScreen
    {
        $summary = $this->summary();
        $detail = match ($component) {
            Component::System => new ServerDetail(),
            Component::Php => new PhpDetail(),
            Component::Symfony => new SymfonyDetail(),
        };

        return new ComponentScreen($component, $detail, new ServiceLocator([
            StackReaderInterface::class => static fn(): StackReaderInterface => $reader,
            StackChecks::class => fn(): StackChecks => new StackChecks([], false, $this->clock),
            StackSummary::class => static fn(): StackSummary => $summary,
        ]), new CardBuilder($this->clock), $this->clock);
    }
}
