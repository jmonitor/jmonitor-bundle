<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\ComponentScreen;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ServerDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\SymfonyDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\StackScreen;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\FakeStackReader;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class StackScreenTest extends TestCase
{
    private MockClock $clock;

    private CountingCachePool $pool;

    /** @var list<string> the services asked to the locator of the screen */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('@' . Snapshots::NOW);
        $this->pool = new CountingCachePool();
    }

    public function testTheScreenIsTheStack(): void
    {
        $screen = $this->screen(new FakeStackReader(self::healthy()));

        self::assertSame('stack', $screen->id());
        self::assertSame('Stack', $screen->label());
        self::assertSame('What runs the application, read live', $screen->heading());
        self::assertTrue($screen->isAvailable());
        self::assertSame(['refresh' => 'Refresh'], $screen->actions());
        self::assertSame([], $this->asked);
    }

    public function testTheOverviewShowsTheSecondNavigationTheChecksThenTheCards(): void
    {
        $blocks = $this->screen(new FakeStackReader(self::troubled()))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::CHECKS, Block::COMPONENTS], array_map(static fn(Block $block): string => $block->type, $blocks));

        self::assertEquals(new SubNav([
            new SubNavItem('Overview', 'stack', true),
            new SubNavItem('Server', 'stack/system', false, new Mark('warn', 'Warning')),
            new SubNavItem('PHP', 'stack/php', false, new Mark('ok', 'Healthy')),
            new SubNavItem('Symfony', 'stack/symfony', false, new Mark('bad', 'Critical')),
        ], 'web-1'), $blocks[0]->content);

        $checks = $blocks[1]->content;
        self::assertInstanceOf(CheckList::class, $checks);
        self::assertSame(
            [
                ['symfony.debug_in_prod', 'bad', 'Critical', 'Symfony', 'stack/symfony', null],
                ['system.disk', 'warn', 'Warning', 'Server', 'stack/system', '1 h ago'],
                ['php.fpm_max_children', 'info', 'Info', 'PHP', 'stack/php', null],
            ],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->mark->tone, $line->mark->label, $line->where, $line->path, $line->age], $checks->lines),
        );
        self::assertSame('Debug mode is on in the prod environment', $checks->lines[0]->sentence);
        self::assertStringStartsWith('Debug mode slows down every request', $checks->lines[0]->explanation);

        $cards = $blocks[2]->content;
        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame(['system', 'php', 'symfony'], array_map(static fn(ComponentCard $card): string => $card->id, $cards->cards));
        self::assertSame(['stack/system', 'stack/php', 'stack/symfony'], array_map(static fn(ComponentCard $card): ?string => $card->path, $cards->cards));
        self::assertSame('web-1', $cards->group);
        self::assertNull($cards->more);
        self::assertSame([], $cards->notDetected);
        self::assertFalse($blocks[2]->reading);
    }

    public function testACheckOfANamedInstanceSaysWhichOne(): void
    {
        $system = Snapshots::system();
        $system['disk']['free'] = 1_000_000_000;
        $state = self::withNode(Snapshots::state(['system' => [new InstanceState('data', null, null, Snapshots::of(Component::System, ['system' => $system]))]]));

        $checks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[1]->content;

        self::assertInstanceOf(CheckList::class, $checks);
        self::assertSame('Server · data', $checks->lines[0]->where);
        self::assertSame('stack/system', $checks->lines[0]->path);
    }

    public function testNothingToCheckIsSaidPlainly(): void
    {
        $php = Snapshots::php();
        unset($php['fpm']);
        $state = self::withNode(Snapshots::stack(Snapshots::of(Component::Php, ['php' => $php])));

        $checks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[1]->content;

        self::assertEquals(new CheckList([]), $checks);
        self::assertSame('Nothing to check', $checks->emptyTitle);
    }

    public function testNothingToCheckIsProvisionalWhileSomethingIsStillRead(): void
    {
        $php = Snapshots::php();
        unset($php['fpm']);
        $state = self::withNode(Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => $php]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]),
        ));

        $checks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[1]->content;

        self::assertInstanceOf(CheckList::class, $checks);
        self::assertSame([], $checks->lines);
        self::assertSame('Nothing to check so far', $checks->emptyTitle);
    }

    public function testAStackWithoutComponentSaysSoInsteadOfNothingToCheck(): void
    {
        $notice = new Notice('info', 'No component is read', 'Every component of the stack is turned off or was not found.');
        $off = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], []);

        $blocks = $this->screen(new FakeStackReader($off))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new SubNav([new SubNavItem('Overview', 'stack', true)], 'web-1'), $blocks[0]->content);
        self::assertEquals($notice, $blocks[1]->content);

        $notFound = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], ['symfony' => 'No kernel']);

        $blocks = $this->screen(new FakeStackReader($notFound))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE, Block::COMPONENTS], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals($notice, $blocks[1]->content);
        self::assertEquals(new ComponentCards([], ['Symfony: No kernel']), $blocks[2]->content);
    }

    public function testAComponentThatCouldNotBeReadKeepsItsCardWithTheReason(): void
    {
        $state = self::withNode(Snapshots::state([
            'php' => [new InstanceState(null, null, null, Snapshots::of(Component::Php, [], ['php' => ResultState::Failed]))],
            'symfony' => [new InstanceState(null, null, null, Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()]))],
        ]));

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));
        $cards = $blocks[2]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame('php', $cards->cards[0]->id);
        self::assertSame(Snapshots::FAILURE, $cards->cards[0]->reason);
        self::assertEquals(new Mark('unknown', 'Unknown'), $cards->cards[0]->mark);
        self::assertInstanceOf(SubNav::class, $blocks[0]->content);
        self::assertEquals(new Mark('unknown', 'Unknown'), $blocks[0]->content->items[1]->mark);
    }

    public function testNotDetectedComponentsAreListedWithTheirReason(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, self::healthy()->components, [
            'system' => 'Server metrics are only read on Linux, this server runs on Darwin.',
            'symfony' => 'No kernel',
        ]);

        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[2]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame(['Server: Server metrics are only read on Linux, this server runs on Darwin.', 'Symfony: No kernel'], $cards->notDetected);
    }

    public function testInstancesBeyondTheEighthAreCountedNotRead(): void
    {
        $read = static fn(string $id): InstanceState => new InstanceState($id, null, null, Snapshots::of(Component::System, ['system' => Snapshots::system()]));
        $unread = static fn(string $id): InstanceState => new InstanceState($id, null, null, null);
        $instances = [...array_map($read, range('a', 'h')), $unread('i'), $unread('j')];
        $state = self::withNode(Snapshots::state(['system' => $instances]));

        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[2]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertCount(8, $cards->cards);
        self::assertSame('2 more connections', $cards->more);

        $state = self::withNode(Snapshots::state(['system' => [...array_map($read, range('a', 'h')), $unread('i')]]));
        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[2]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame('1 more connection', $cards->more);
    }

    public function testACacheOutOfOrderIsToldAndNothingKeepsReading(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::OutOfOrder, Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => Snapshots::php()], ['eol.php' => ResultState::Pending]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]),
        )->components, []);

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE, Block::CHECKS, Block::COMPONENTS], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new Notice('warn', 'The live stack needs a working cache', 'Only what PHP can read in its own process is shown. Check the cache.app pool of the application.'), $blocks[1]->content);
        self::assertSame([false, false, false, false], array_map(static fn(Block $block): bool => $block->reading, $blocks));
        self::assertInstanceOf(ComponentCards::class, $blocks[3]->content);
        self::assertSame([false, false], array_map(static fn(ComponentCard $card): bool => $card->reading, $blocks[3]->content->cards));

        foreach ($blocks[3]->content->cards as $card) {
            foreach ($card->vitals as $vital) {
                self::assertNotSame('Reading…', $vital->value, $card->id . ' ' . $vital->label);
            }
        }
        self::assertSame('—', $blocks[3]->content->cards[1]->vitals[2]->value);
    }

    public function testAColdCacheIsNotTold(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Cold, self::healthy()->components, []);

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::CHECKS, Block::COMPONENTS], array_map(static fn(Block $block): string => $block->type, $blocks));
    }

    public function testACardStillReadingMarksTheBlock(): void
    {
        $state = self::withNode(Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => Snapshots::php()]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]),
        ));

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([false, false, true], array_map(static fn(Block $block): bool => $block->reading, $blocks));
    }

    public function testAnEngineFailureIsToldInsteadOfAnEmptyStack(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], [], 'Cannot reach redis://cache:6379: Connection refused');

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new SubNav([new SubNavItem('Overview', 'stack', true)], 'web-1'), $blocks[0]->content);
        self::assertEquals(new Notice('bad', 'The stack could not be read', 'Cannot reach redis://cache:6379: Connection refused'), $blocks[1]->content);
        self::assertSame([], $this->pool->calls, 'nothing is remembered');
    }

    public function testAPageReadsAsAPageAndAFollowUpAsAFragment(): void
    {
        $reader = new FakeStackReader(self::healthy());
        $screen = $this->screen($reader);

        $screen->blocks(new ScreenRequest(Snapshots::NOW));
        $screen->blocks(new ScreenRequest(Snapshots::NOW, true));

        self::assertSame(['overview Page', 'overview Fragment'], $reader->calls);
    }

    public function testRefreshForgetsTheWholeStack(): void
    {
        $reader = new FakeStackReader(self::healthy());

        $this->screen($reader)->run('refresh');

        self::assertSame(['refresh all'], $reader->calls);
    }

    public function testTheOverviewIsRememberedForTheBadge(): void
    {
        $this->screen(new FakeStackReader(self::troubled()))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame(
            ['at' => Snapshots::NOW, 'critical' => 1, 'warning' => 1, 'components' => ['system' => 'warning', 'php' => 'healthy', 'symfony' => 'critical']],
            $this->summary()->last(),
        );
    }

    public function testTheBadgeCountsTheCriticalAndWarningChecksOfTheLastVisit(): void
    {
        $this->screen(new FakeStackReader(self::troubled()))->blocks(new ScreenRequest(Snapshots::NOW));
        $this->clock->sleep(185);

        self::assertEquals(
            new NavBadge('2', 'bad', '1 critical, 1 warning at the last visit of the Stack overview, 3 min ago'),
            $this->screen(new FakeStackReader(self::troubled()))->navBadge(),
        );

        $system = Snapshots::system();
        $system['disk']['free'] = 10_000_000_000;
        $system['ram']['available'] = 800_000_000;
        $warnings = self::withNode(Snapshots::stack(Snapshots::of(Component::System, ['system' => $system])));
        $this->screen(new FakeStackReader($warnings))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(
            new NavBadge('2', 'warn', '2 warnings at the last visit of the Stack overview, just now'),
            $this->screen(new FakeStackReader(self::troubled()))->navBadge(),
        );

        $critical = self::withNode(Snapshots::stack(Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true] + Snapshots::symfony()])));
        $this->screen(new FakeStackReader($critical))->blocks(new ScreenRequest(Snapshots::NOW));
        $this->clock->sleep(42);

        self::assertEquals(
            new NavBadge('1', 'bad', '1 critical at the last visit of the Stack overview, 42 s ago'),
            $this->screen(new FakeStackReader(self::troubled()))->navBadge(),
        );
    }

    public function testNoVisitNoBadge(): void
    {
        self::assertNull($this->screen(new FakeStackReader(self::troubled()))->navBadge());
    }

    public function testNothingToCheckNoBadge(): void
    {
        $this->screen(new FakeStackReader(self::healthy()))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertNotNull($this->summary()->last());
        self::assertNull($this->screen(new FakeStackReader(self::healthy()))->navBadge());
    }

    public function testTheBadgeAsksForNothingButTheSummary(): void
    {
        $this->screen(new FakeStackReader(self::troubled()))->blocks(new ScreenRequest(Snapshots::NOW));
        $this->asked = [];
        $this->pool->reset();

        $badge = $this->screen(new FakeStackReader(self::troubled()))->navBadge();

        self::assertNotNull($badge);
        self::assertSame([StackSummary::class], $this->asked);
        self::assertSame(['getItem' => 1], $this->pool->calls);
    }

    public function testEachComponentHasItsDetailAsASubScreen(): void
    {
        $reader = new FakeStackReader(self::healthy());
        $screen = $this->screen($reader);

        foreach (['system' => 'Server', 'php' => 'PHP', 'symfony' => 'Symfony'] as $id => $label) {
            $child = $screen->child($id);

            self::assertInstanceOf(ComponentScreen::class, $child);
            self::assertSame($id, $child->id());
            self::assertSame($label, $child->label());
        }

        foreach (['mysql', 'overview', 'stack', 'php-fpm'] as $id) {
            self::assertNull($screen->child($id), $id);
        }

        self::assertSame([], $reader->calls);
        self::assertSame([], $this->asked);
    }

    public function testAVisitThenItsBadgeCostNoCacheReadForTheBadge(): void
    {
        $screen = $this->screen(new FakeStackReader(self::troubled()));
        $screen->blocks(new ScreenRequest(Snapshots::NOW));
        $this->pool->reset();

        self::assertNotNull($screen->navBadge());
        self::assertSame(0, $this->pool->roundTrips());
    }

    /**
     * Server, PHP and Symfony read; only an Info check on PHP-FPM.
     */
    private static function healthy(): StackState
    {
        return self::withNode(Snapshots::stack(
            Snapshots::of(Component::System, ['system' => Snapshots::system()]),
            Snapshots::of(Component::Php, ['php' => Snapshots::php()]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()]),
        ));
    }

    /**
     * A disk 90 % full read an hour ago, debug mode in prod, the Info check on PHP-FPM.
     */
    private static function troubled(): StackState
    {
        $system = Snapshots::system();
        $system['disk']['free'] = 10_000_000_000;

        return self::withNode(Snapshots::stack(
            Snapshots::of(Component::System, ['system' => $system], ['system' => ResultState::Stale]),
            Snapshots::of(Component::Php, ['php' => Snapshots::php()]),
            Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true] + Snapshots::symfony()]),
        ));
    }

    private static function withNode(StackState $state): StackState
    {
        return new StackState('web-1', $state->readAt, $state->cache, $state->components, $state->notDetected, $state->error);
    }

    private function summary(): StackSummary
    {
        return new StackSummary(new ServiceLocator(['cache' => fn(): CountingCachePool => $this->pool]), new WebNode('web-1'), $this->clock);
    }

    private function screen(StackReaderInterface $reader): StackScreen
    {
        $summary = $this->summary();
        $services = [
            StackReaderInterface::class => static fn(): StackReaderInterface => $reader,
            StackChecks::class => fn(): StackChecks => new StackChecks([], false, $this->clock),
            StackSummary::class => static fn(): StackSummary => $summary,
        ];

        return new StackScreen(new ServiceLocator(array_map(fn(\Closure $factory): \Closure => function () use ($factory): object {
            $service = $factory();
            $this->asked[] = $service instanceof StackReaderInterface ? StackReaderInterface::class : $service::class;

            return $service;
        }, $services)), new CardBuilder($this->clock), $this->clock, new ServiceLocator([
            'system' => static fn(): ServerDetail => new ServerDetail(),
            'php' => static fn(): PhpDetail => new PhpDetail(),
            'symfony' => static fn(): SymfonyDetail => new SymfonyDetail(),
        ]));
    }
}
