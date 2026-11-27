<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\Page;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Server;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\ComponentScreen;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\NginxDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ServerDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\SymfonyDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\StackScreen;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\FakeStackReader;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\WebServerPayloads;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame([], $this->asked);
    }

    public function testTheActionIsNamedReadAgainNow(): void
    {
        self::assertSame(['refresh' => 'Read again now'], $this->screen(new FakeStackReader(self::healthy()))->actions());
    }

    /**
     * @return iterable<string, array{StackState, string}>
     */
    public static function leads(): iterable
    {
        $php = Snapshots::php();
        unset($php['fpm']);
        $calm = Snapshots::of(Component::Php, ['php' => $php]);
        $disk = Snapshots::system();
        $disk['disk']['free'] = 10_000_000_000;
        $full = $disk;
        $full['ram']['available'] = 800_000_000;
        $failed = static fn(Component $component): ComponentSnapshot => Snapshots::of($component, [], [$component->value => ResultState::Failed]);
        $debug = Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true] + Snapshots::symfony()]);
        $unread = [new InstanceState('cache', null, null, null)];
        $read = static fn(ComponentSnapshot $snapshot): array => [new InstanceState(null, null, null, $snapshot)];

        yield 'nothing' => [Snapshots::stack($calm), 'Everything that runs your application answers.'];
        yield 'an info alone' => [self::healthy(), 'Everything that runs your application answers.'];
        yield 'a warning' => [Snapshots::stack(Snapshots::of(Component::System, ['system' => $disk]), $calm), 'Everything that runs your application answers. 1 warning, on Server.'];
        yield 'a warning beside an info' => [Snapshots::stack(Snapshots::of(Component::System, ['system' => $disk]), Snapshots::of(Component::Php, ['php' => Snapshots::php()])), 'Everything that runs your application answers. 1 warning, on Server.'];
        yield 'a critical and a warning' => [Snapshots::stack(Snapshots::of(Component::System, ['system' => $disk]), $calm, $debug), 'What runs your application needs attention. 1 critical and 1 warning, on Server and Symfony.'];
        yield 'two components' => [Snapshots::stack(Snapshots::of(Component::System, ['system' => $disk]), $failed(Component::Php)), 'Part of what runs your application could not be read. 2 warnings, on Server and PHP.'];
        yield 'a critical and warnings' => [Snapshots::stack(Snapshots::of(Component::System, ['system' => $full]), $calm, $debug), 'What runs your application needs attention. 1 critical and 2 warnings, on Server and Symfony.'];
        yield 'three components' => [Snapshots::stack(Snapshots::of(Component::System, ['system' => $disk]), $failed(Component::Php), $debug), 'What runs your application needs attention. 1 critical and 2 warnings, on Server, PHP and Symfony.'];
        yield 'four components' => [Snapshots::stack(Snapshots::of(Component::System, ['system' => $disk]), $failed(Component::Php), $debug, $failed(Component::Redis)), 'What runs your application needs attention. 1 critical and 3 warnings, on 4 components.'];
        yield 'two critical' => [Snapshots::stack(Snapshots::of(Component::System, ['system' => ['disk' => ['total' => 100_000_000_000, 'free' => 1_000_000_000]] + Snapshots::system()]), $debug), 'What runs your application needs attention. 2 critical, on Server and Symfony.'];
        yield 'a component not read' => [Snapshots::state(['php' => $read($calm), 'redis' => $unread]), 'Part of what runs your application could not be read.'];
        yield 'a component not read beside a warning' => [Snapshots::state(['system' => $read(Snapshots::of(Component::System, ['system' => $disk])), 'php' => $read($calm), 'redis' => $unread]), 'Part of what runs your application could not be read. 1 warning, on Server.'];
        yield 'a component not read beside a critical' => [Snapshots::state(['php' => $read($calm), 'symfony' => $read($debug), 'redis' => $unread]), 'What runs your application needs attention. 1 critical, on Symfony.'];
        yield 'reading' => [self::reading(), 'Reading what runs your application…'];
    }

    #[DataProvider('leads')]
    public function testTheLeadSumsUpTheStack(StackState $state, string $sentence): void
    {
        $blocks = $this->screen(new FakeStackReader(self::withNode($state)))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame(Block::LEAD, $blocks[1]->type);
        self::assertEquals(new Lead($sentence, 'Read on your servers as you open this screen. Nothing is kept: it shows now, not the past.'), $blocks[1]->content);
    }

    public function testNoLeadWhenTheEngineFailedOrNothingIsRead(): void
    {
        $failed = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], [], 'Cannot reach redis://cache:6379: Connection refused');
        $off = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], ['symfony' => 'No kernel']);
        $offWithoutCache = new StackState('web-1', Snapshots::NOW, CacheState::OutOfOrder, [], []);

        foreach ([$failed, $off, $offWithoutCache] as $state) {
            $types = array_map(static fn(Block $block): string => $block->type, $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW)));

            self::assertNotContains(Block::LEAD, $types);
            self::assertNotContains(Block::GUIDE, $types);
        }
    }

    public function testTheLeadTitlesThePage(): void
    {
        $screen = $this->screen(new FakeStackReader(new StackState('web-1', Snapshots::NOW, CacheState::OutOfOrder, self::healthy()->components, [])));
        $blocks = $screen->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::LEAD, Block::NOTICE], array_map(static fn(Block $block): string => $block->type, array_slice($blocks, 0, 3)));

        $body = (new Page($screen, $blocks, null, '', '', []))->body();

        self::assertSame(Block::LEAD, $body[0]->type);
        self::assertSame(BlockState::Ready, $body[0]->state);
    }

    public function testTheGuideClosesTheOverview(): void
    {
        foreach ([self::healthy(), self::troubled(), self::reading()] as $state) {
            $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));
            $guide = end($blocks);

            self::assertNotFalse($guide);
            self::assertSame(Block::GUIDE, $guide->type);
            self::assertEquals(new Guide([
                new GuideItem('Four words.', 'Healthy: nothing to do. Info: good to know. Warning: act before it becomes a problem. Critical: it is a problem now.'),
                new GuideItem('Found by itself.', 'The components come from your Symfony configuration: Doctrine connections, cache and session DSN. Nothing to declare.'),
                new GuideItem('A check that does not apply to you.', 'Each one has a name, such as php.opcache_memory. Put it in stack.ignore_checks to silence it.'),
            ]), $guide->content);
        }
    }

    public function testTheOverviewShowsTheSecondNavigationTheChecksThenTheCards(): void
    {
        $blocks = $this->screen(new FakeStackReader(self::troubled()))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::LEAD, Block::CHECKS, Block::COMPONENTS, Block::GUIDE], array_map(static fn(Block $block): string => $block->type, $blocks));

        self::assertEquals(new SubNav('Stack', [
            new SubNavItem('All components', 'stack', true, icon: 'stack'),
            new SubNavItem('Server', 'stack/system', false, new Mark('warn', 'Warning'), icon: 'system'),
            new SubNavItem('PHP', 'stack/php', false, icon: 'php'),
            new SubNavItem('Symfony', 'stack/symfony', false, new Mark('bad', 'Critical'), icon: 'symfony'),
        ], 'web-1'), $blocks[0]->content);

        $checks = $blocks[2]->content;
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

        $cards = $blocks[3]->content;
        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame(['system', 'php', 'symfony'], array_map(static fn(ComponentCard $card): string => $card->id, $cards->cards));
        self::assertSame(['stack/system', 'stack/php', 'stack/symfony'], array_map(static fn(ComponentCard $card): ?string => $card->path, $cards->cards));
        self::assertSame('web-1', $cards->group);
        self::assertNull($cards->more);
        self::assertSame([], $cards->notDetected);
        self::assertTrue($blocks[3]->reading, 'the disk read an hour ago is read again');
    }

    public function testACheckOfANamedInstanceSaysWhichOne(): void
    {
        $system = Snapshots::system();
        $system['disk']['free'] = 1_000_000_000;
        $state = self::withNode(Snapshots::state(['system' => [new InstanceState('data', null, null, Snapshots::of(Component::System, ['system' => $system]))]]));

        $checks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[2]->content;

        self::assertInstanceOf(CheckList::class, $checks);
        self::assertSame('Server · data', $checks->lines[0]->where);
        self::assertSame('stack/system', $checks->lines[0]->path);
    }

    public function testNothingToCheckIsSaidPlainly(): void
    {
        $php = Snapshots::php();
        unset($php['fpm']);
        $state = self::withNode(Snapshots::stack(Snapshots::of(Component::Php, ['php' => $php])));

        $checks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[2]->content;

        self::assertEquals(new CheckList([], 'Nothing to flag', '', 'Worth a look'), $checks);
        self::assertSame('Nothing to flag', $checks->emptyTitle);
    }

    public function testNothingToCheckIsProvisionalWhileSomethingIsStillRead(): void
    {
        $php = Snapshots::php();
        unset($php['fpm']);
        $state = self::withNode(Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => $php]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]),
        ));

        $checks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[2]->content;

        self::assertInstanceOf(CheckList::class, $checks);
        self::assertSame([], $checks->lines);
        self::assertSame('Nothing to flag so far', $checks->emptyTitle);
    }

    public function testAStackWithoutComponentSaysSoInsteadOfNothingToCheck(): void
    {
        $notice = new Notice('info', 'No component is read', 'Every component of the stack is turned off or was not found.');
        $off = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], []);

        $blocks = $this->screen(new FakeStackReader($off))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new SubNav('Stack', [new SubNavItem('All components', 'stack', true, icon: 'stack')], 'web-1'), $blocks[0]->content);
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
        $cards = $blocks[3]->content;

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

        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame(['Server: Server metrics are only read on Linux, this server runs on Darwin.', 'Symfony: No kernel'], $cards->notDetected);
    }

    public function testWebServersThatDoNotServeThePageAreLeftOutWhenOneDoes(): void
    {
        $nginx = Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(), 'nginx.config' => WebServerPayloads::nginxConfig()]);
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, [...self::healthy()->components, ...Snapshots::stack($nginx)->components], [
            'frankenphp' => 'FrankenPHP is not serving this page.',
            'apache' => 'Apache is not serving this page.',
        ]);

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));
        $cards = $blocks[3]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame([], $cards->notDetected);
        self::assertSame(['system', 'php', 'symfony', 'nginx'], array_map(static fn(ComponentCard $card): string => $card->id, $cards->cards));
        self::assertSame('stack/nginx', $cards->cards[3]->path);

        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, Snapshots::stack($nginx)->components, [
            'system' => 'Server metrics are only read on Linux, this server runs on Darwin.',
            'frankenphp' => 'FrankenPHP is not serving this page.',
            'apache' => 'Apache is not serving this page.',
        ]);
        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame(['Server: Server metrics are only read on Linux, this server runs on Darwin.'], $cards->notDetected);
    }

    public function testWithoutAnyWebServerOneEntrySaysSo(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, self::healthy()->components, [
            'frankenphp' => 'FrankenPHP is not serving this page.',
            'apache' => 'Apache is not serving this page.',
            'nginx' => 'Nginx is not serving this page.',
        ]);

        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame(['Web server: FrankenPHP, Apache and Nginx were not found serving this page'], $cards->notDetected);

        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], [
            'system' => 'Server metrics are only read on Linux, this server runs on Darwin.',
            'frankenphp' => 'FrankenPHP is not serving this page.',
            'apache' => 'Apache is not serving this page.',
            'nginx' => 'Nginx is not serving this page.',
        ]);

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new ComponentCards([], [
            'Server: Server metrics are only read on Linux, this server runs on Darwin.',
            'Web server: FrankenPHP, Apache and Nginx were not found serving this page',
        ]), $blocks[2]->content);
    }

    public function testAWebServerTurnedOffIsNotCalledMissing(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, self::healthy()->components, [
            'apache' => 'Apache is not serving this page.',
            'nginx' => 'Nginx is not serving this page.',
        ]);

        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame(['Apache: Apache is not serving this page.', 'Nginx: Nginx is not serving this page.'], $cards->notDetected);
    }

    public function testAnIncompleteReadDoesNotReplaceTheSummaryOfACompleteOne(): void
    {
        $this->screen(new FakeStackReader(self::troubled()))->blocks(new ScreenRequest(Snapshots::NOW));
        $complete = $this->summary()->last();
        $this->clock->sleep(30);

        $this->screen(new FakeStackReader(self::reading()))->blocks(new ScreenRequest(Snapshots::NOW, true));

        self::assertNotNull($complete);
        self::assertSame($complete, $this->summary()->last());

        $this->screen(new FakeStackReader(self::healthy()))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame(
            ['at' => Snapshots::NOW + 30, 'critical' => 0, 'warning' => 0, 'components' => ['system' => 'healthy', 'php' => 'healthy', 'symfony' => 'healthy']],
            $this->summary()->last(),
        );
    }

    public function testAFirstReadStillWritesASummary(): void
    {
        $this->screen(new FakeStackReader(self::reading()))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame(['at' => Snapshots::NOW, 'critical' => 0, 'warning' => 0, 'components' => ['php' => 'healthy', 'symfony' => 'healthy']], $this->summary()->last());
    }

    public function testAWebServerHasItsDetailOnceWired(): void
    {
        $screen = $this->screen(new FakeStackReader(self::healthy()), [
            'nginx' => static fn(): NginxDetail => new NginxDetail(),
        ]);

        $child = $screen->child('nginx');

        self::assertInstanceOf(ComponentScreen::class, $child);
        self::assertSame('Nginx', $child->label());
        self::assertNull($screen->child('apache'));
    }

    public function testInstancesBeyondTheEighthAreCountedNotRead(): void
    {
        $read = static fn(string $id): InstanceState => new InstanceState($id, null, null, Snapshots::of(Component::System, ['system' => Snapshots::system()]));
        $unread = static fn(string $id): InstanceState => new InstanceState($id, null, null, null);
        $instances = [...array_map($read, range('a', 'h')), $unread('i'), $unread('j')];
        $state = self::withNode(Snapshots::state(['system' => $instances]));

        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertCount(8, $cards->cards);
        self::assertSame('2 more connections', $cards->more);

        $state = self::withNode(Snapshots::state(['system' => [...array_map($read, range('a', 'h')), $unread('i')]]));
        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame('1 more connection', $cards->more);
    }

    public function testTheCardAndItsChecksLinkToTheirConnection(): void
    {
        $status = DatabasePayloads::mysqlStatus();
        $status['Threads_connected'] = '99';
        $snapshot = Snapshots::of(Component::Mysql, ['mysql' => $status, 'mysql.variables' => DatabasePayloads::mysqlVariables()]);
        $state = self::withNode(Snapshots::state(['mysql' => [new InstanceState('a/b é', 'app', null, $snapshot, new Server('db1:3306', 'db1:3306'))]]));

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertInstanceOf(CheckList::class, $blocks[2]->content);
        self::assertSame(
            [['mysql.connections', 'stack/mysql', ['connection' => 'a/b é']]],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->path, $line->query], $blocks[2]->content->lines),
        );
        self::assertInstanceOf(ComponentCards::class, $blocks[3]->content);
        self::assertSame('stack/mysql', $blocks[3]->content->cards[0]->path);
        self::assertSame(['connection' => 'a/b é'], $blocks[3]->content->cards[0]->query);

        $php = $this->screen(new FakeStackReader(self::healthy()))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

        self::assertInstanceOf(ComponentCards::class, $php);
        self::assertSame([[], [], []], array_map(static fn(ComponentCard $card): array => $card->query, $php->cards));
    }

    public function testAServerWithTwoConnectionsHasOneCardThatSaysSo(): void
    {
        $snapshot = Snapshots::of(Component::Mysql, ['mysql' => DatabasePayloads::mysqlStatus(), 'mysql.variables' => DatabasePayloads::mysqlVariables()]);
        $server = new Server('db1:3306', 'db1:3306');
        $state = self::withNode(Snapshots::state(['mysql' => [
            new InstanceState('main', 'shop', null, $snapshot, $server, 1),
            new InstanceState('reports', 'reports', null, null, $server, 1),
        ]]));
        $state = new StackState($state->node, $state->readAt, $state->cache, [new ComponentState(Component::Mysql, $state->components[0]->instances, 0)], []);

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new SubNav('Stack', [
            new SubNavItem('All components', 'stack', true, icon: 'stack'),
            new SubNavItem('MySQL', 'stack/mysql', false, icon: 'mysql'),
        ], 'web-1'), $blocks[0]->content);
        $cards = $blocks[3]->content;
        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame(['mysql.main'], array_map(static fn(ComponentCard $card): string => $card->id, $cards->cards));
        self::assertSame('MySQL · main', $cards->cards[0]->name);
        self::assertSame('db1:3306 · 2 connections', $cards->cards[0]->note);
        self::assertNull($cards->more);
        self::assertSame(['mysql' => 'healthy'], $this->summary()->last()['components'] ?? null);
    }

    public function testMoreThanEightServersAreCounted(): void
    {
        $read = static fn(int $i): InstanceState => new InstanceState('c' . $i, 'shop', null, Snapshots::of(Component::Mysql, ['mysql' => DatabasePayloads::mysqlStatus()]), new Server('db' . $i . ':3306', 'db' . $i . ':3306'));
        $unread = static fn(int $i): InstanceState => new InstanceState('c' . $i, 'shop', null, null, new Server('db' . $i . ':3306', 'db' . $i . ':3306'));

        foreach ([9 => '1 more server', 10 => '2 more servers'] as $servers => $more) {
            $state = self::withNode(Snapshots::state(['mysql' => [...array_map($read, range(1, 8)), ...array_map($unread, range(9, $servers))]]));

            $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

            self::assertInstanceOf(ComponentCards::class, $cards);
            self::assertCount(8, $cards->cards);
            self::assertSame($more, $cards->more);
        }
    }

    public function testEachPostgresqlConnectionHasItsCard(): void
    {
        $state = self::withNode(Snapshots::state(['postgresql' => [
            self::postgresql('main', 'db1:5432', true),
            self::postgresql('reports', 'db1:5432', true),
            self::postgresql('archive', 'db2:5432', true),
            self::postgresql('spare', 'db1:5432', false),
        ]]));

        $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

        self::assertInstanceOf(ComponentCards::class, $cards);
        self::assertSame(['postgresql.main', 'postgresql.reports', 'postgresql.archive'], array_map(static fn(ComponentCard $card): string => $card->id, $cards->cards));
        self::assertSame(['db1:5432', 'db1:5432', 'db2:5432'], array_map(static fn(ComponentCard $card): ?string => $card->note, $cards->cards));
        self::assertSame([['connection' => 'main'], ['connection' => 'reports'], ['connection' => 'archive']], array_map(static fn(ComponentCard $card): array => $card->query, $cards->cards));
        self::assertSame(['stack/postgresql'], array_values(array_unique(array_map(static fn(ComponentCard $card): ?string => $card->path, $cards->cards))));
        self::assertSame('1 more connection', $cards->more);
    }

    public function testBeyondEightPostgresqlConnectionsTheRestIsCounted(): void
    {
        foreach ([9 => '1 more connection', 10 => '2 more connections'] as $connections => $more) {
            $state = self::withNode(Snapshots::state(['postgresql' => array_map(
                static fn(int $i): InstanceState => self::postgresql('c' . $i, 'db1:5432', $i <= 8),
                range(1, $connections),
            )]));

            $cards = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW))[3]->content;

            self::assertInstanceOf(ComponentCards::class, $cards);
            self::assertCount(8, $cards->cards);
            self::assertSame($more, $cards->more);
        }
    }

    public function testACacheOutOfOrderIsToldAndNothingKeepsReading(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::OutOfOrder, Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => Snapshots::php()], ['eol.php' => ResultState::Pending]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]),
        )->components, []);

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::LEAD, Block::NOTICE, Block::CHECKS, Block::COMPONENTS, Block::GUIDE], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new Notice('warn', 'The live stack needs a working cache', 'Only what PHP can read in its own process is shown. Check the cache.app pool of the application.'), $blocks[2]->content);
        self::assertSame([false, false, false, false, false, false], array_map(static fn(Block $block): bool => $block->reading, $blocks));
        self::assertInstanceOf(ComponentCards::class, $blocks[4]->content);
        self::assertSame([false, false], array_map(static fn(ComponentCard $card): bool => $card->reading, $blocks[4]->content->cards));

        foreach ($blocks[4]->content->cards as $card) {
            foreach ($card->vitals as $vital) {
                self::assertNotSame('Reading…', $vital->value, $card->id . ' ' . $vital->label);
            }
        }
        self::assertSame('—', $blocks[4]->content->cards[1]->vitals[2]->value);
    }

    public function testAColdCacheIsNotTold(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Cold, self::healthy()->components, []);

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::LEAD, Block::CHECKS, Block::COMPONENTS, Block::GUIDE], array_map(static fn(Block $block): string => $block->type, $blocks));
    }

    public function testACardStillReadingMarksTheBlock(): void
    {
        $state = self::withNode(Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => Snapshots::php()]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]),
        ));

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([false, false, false, true, false], array_map(static fn(Block $block): bool => $block->reading, $blocks));
    }

    public function testAnOutdatedValueIsReadAgainUnlessItsRenewalFailed(): void
    {
        $php = Snapshots::php();
        unset($php['fpm']);
        $outdated = Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => ['up_to_date' => true, 'outdated_recipes' => []]], ['symfony.flex' => ResultState::Stale]);
        $state = self::withNode(Snapshots::stack(Snapshots::of(Component::Php, ['php' => $php]), $outdated));

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([false, false, false, true, false], array_map(static fn(Block $block): bool => $block->reading, $blocks));
        self::assertInstanceOf(ComponentCards::class, $blocks[3]->content);
        self::assertSame([false, false], array_map(static fn(ComponentCard $card): bool => $card->reading, $blocks[3]->content->cards));
        self::assertInstanceOf(CheckList::class, $blocks[2]->content);
        self::assertSame('Nothing to flag', $blocks[2]->content->emptyTitle);

        $failed = new ComponentSnapshot(Component::Symfony, [
            ...$outdated->results,
            'symfony.flex' => new CollectorResult('symfony.flex', ['up_to_date' => true, 'outdated_recipes' => []], ResultState::Stale, 'composer could not run', Snapshots::NOW - Snapshots::STALE_AGE, expiresAt: Snapshots::NOW + 60),
        ]);
        $state = self::withNode(Snapshots::stack(Snapshots::of(Component::Php, ['php' => $php]), $failed));

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([false, false, false, false, false], array_map(static fn(Block $block): bool => $block->reading, $blocks));

        $outOfOrder = new StackState('web-1', Snapshots::NOW, CacheState::OutOfOrder, Snapshots::stack($outdated)->components, []);

        $blocks = $this->screen(new FakeStackReader($outOfOrder))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([false, false, false, false, false, false], array_map(static fn(Block $block): bool => $block->reading, $blocks));

        $blocks = $this->screen(new FakeStackReader(self::healthy()))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([false, false, false, false, false], array_map(static fn(Block $block): bool => $block->reading, $blocks));
    }

    public function testAnEngineFailureIsToldInsteadOfAnEmptyStack(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], [], 'Cannot reach redis://cache:6379: Connection refused');

        $blocks = $this->screen(new FakeStackReader($state))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new SubNav('Stack', [new SubNavItem('All components', 'stack', true, icon: 'stack')], 'web-1'), $blocks[0]->content);
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
            NavBadge::of(1, 1, 'at the last read of the stack, 3 min ago'),
            $this->screen(new FakeStackReader(self::troubled()))->navBadge(),
        );

        $system = Snapshots::system();
        $system['disk']['free'] = 10_000_000_000;
        $system['ram']['available'] = 800_000_000;
        $warnings = self::withNode(Snapshots::stack(Snapshots::of(Component::System, ['system' => $system])));
        $this->screen(new FakeStackReader($warnings))->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(
            NavBadge::of(0, 2, 'at the last read of the stack, just now'),
            $this->screen(new FakeStackReader(self::troubled()))->navBadge(),
        );

        $critical = self::withNode(Snapshots::stack(Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true] + Snapshots::symfony()])));
        $this->screen(new FakeStackReader($critical))->blocks(new ScreenRequest(Snapshots::NOW));
        $this->clock->sleep(42);

        self::assertEquals(
            NavBadge::of(1, 0, 'at the last read of the stack, 42 s ago'),
            $this->screen(new FakeStackReader(self::troubled()))->navBadge(),
        );
    }

    /**
     * @return iterable<string, array{int, int, list<string>}>
     */
    public static function badgeWords(): iterable
    {
        yield '1 critical and 1 warning' => [1, 1, ['1 critical at the last read of the stack, just now', '1 warning at the last read of the stack, just now']];
        yield '2 critical' => [2, 0, ['2 critical at the last read of the stack, just now']];
        yield '1 warning' => [0, 1, ['1 warning at the last read of the stack, just now']];
        yield '3 warnings' => [0, 3, ['3 warnings at the last read of the stack, just now']];
    }

    /**
     * @param list<string> $mentions
     */
    #[DataProvider('badgeWords')]
    public function testTheBadgeSaysEachCountInWords(int $critical, int $warnings, array $mentions): void
    {
        $findings = [];

        for ($i = 0; $i < $critical; ++$i) {
            $findings[] = new Finding('symfony.debug_' . $i, Component::Symfony, null, Severity::Critical, 'Debug', 'Debug in prod');
        }

        for ($i = 0; $i < $warnings; ++$i) {
            $findings[] = new Finding('system.disk_' . $i, Component::System, null, Severity::Warning, 'Disk', 'Disk almost full');
        }

        $this->summary()->remember(self::healthy(), new StackHealth($findings, []));

        $badge = $this->screen(new FakeStackReader(self::healthy()))->navBadge();

        self::assertNotNull($badge);
        self::assertSame([$critical, $warnings], [$badge->critical, $badge->warning]);
        self::assertSame($mentions, array_column($badge->pills(), 'mention'));
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

    /**
     * PHP read, the Flex recipes of Symfony still read.
     */
    private static function reading(): StackState
    {
        $php = Snapshots::php();
        unset($php['fpm']);

        return self::withNode(Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => $php]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]),
        ));
    }

    private static function postgresql(string $name, string $server, bool $read): InstanceState
    {
        $snapshot = $read ? Snapshots::of(Component::Postgresql, ['postgresql' => DatabasePayloads::postgresqlActivity(), 'postgresql.settings' => DatabasePayloads::postgresqlSettings()]) : null;

        return new InstanceState($name, $name, null, $snapshot, new Server($server, $server));
    }

    private static function withNode(StackState $state): StackState
    {
        return new StackState('web-1', $state->readAt, $state->cache, $state->components, $state->notDetected, $state->error);
    }

    private function summary(): StackSummary
    {
        return new StackSummary(new ServiceLocator(['cache' => fn(): CountingCachePool => $this->pool]), new WebNode('web-1'), $this->clock);
    }

    /**
     * @param array<string, \Closure(): object> $details added to those of Server, PHP and Symfony
     */
    private function screen(StackReaderInterface $reader, array $details = []): StackScreen
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
            ...$details,
        ]));
    }
}
