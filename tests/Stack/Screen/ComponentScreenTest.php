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
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNav;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNavItem;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNavRow;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Server;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\ComponentScreen;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\DetailInterface;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ServerDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\SymfonyDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\FakeStackReader;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RedisPayloads;
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
            self::assertSame(['refresh' => 'Read again now'], $screen->actions());
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

        self::assertEquals(new CheckList([], 'Nothing to flag on Symfony', '', 'Worth a look'), $blocks[2]->content);
    }

    public function testTheChecksOfTheDetailAreWorthALook(): void
    {
        $php = Snapshots::php();
        $php['display_errors'] = '1';

        $blocks = $this->screen(new FakeStackReader(self::stack(Snapshots::of(Component::Php, ['php' => $php]))), Component::Php)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertInstanceOf(CheckList::class, $blocks[2]->content);
        self::assertSame(['Worth a look', 'Nothing to flag on PHP'], [$blocks[2]->content->title, $blocks[2]->content->emptyTitle]);
        self::assertCount(2, $blocks[2]->content->lines);
    }

    public function testTheDetailHasNeitherLeadNorGuide(): void
    {
        foreach ([Component::System, Component::Php, Component::Symfony] as $component) {
            $state = self::stack(Snapshots::of(Component::System, ['system' => Snapshots::system()]), Snapshots::of(Component::Php, ['php' => Snapshots::php()]), Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true] + Snapshots::symfony()]));
            $types = array_map(static fn(Block $block): string => $block->type, $this->screen(new FakeStackReader($state), $component)->blocks(new ScreenRequest(Snapshots::NOW)));

            self::assertNotContains(Block::LEAD, $types, $component->value);
            self::assertNotContains(Block::GUIDE, $types, $component->value);
        }
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
        $this->remember(['system' => 'warning', 'php' => 'healthy', 'memcached' => 'critical', 'symfony' => 'flaky']);
        $php = Snapshots::php();
        $php['display_errors'] = '1';
        $state = self::stack(Snapshots::of(Component::Php, ['php' => $php]));

        $blocks = $this->screen(new FakeStackReader($state), Component::Php)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new SubNav('Stack', [
            new SubNavItem('All components', 'stack', icon: 'stack'),
            new SubNavItem('Server', 'stack/system', false, new Mark('warn', 'Warning'), icon: 'system'),
            new SubNavItem('PHP', 'stack/php', true, new Mark('warn', 'Warning'), icon: 'php'),
        ], 'web-1'), $blocks[0]->content);

        $this->remember(['symfony' => 'critical', 'system' => 'healthy']);
        $blocks = $this->screen(new FakeStackReader($state), Component::Php)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new SubNav('Stack', [
            new SubNavItem('All components', 'stack', icon: 'stack'),
            new SubNavItem('Server', 'stack/system', false, icon: 'system'),
            new SubNavItem('PHP', 'stack/php', true, new Mark('warn', 'Warning'), icon: 'php'),
            new SubNavItem('Symfony', 'stack/symfony', false, new Mark('bad', 'Critical'), icon: 'symfony'),
        ], 'web-1'), $blocks[0]->content);
    }

    public function testWithoutLastVisitTheSecondNavigationOnlyHasTheComponent(): void
    {
        $blocks = $this->screen(new FakeStackReader(self::stack(Snapshots::of(Component::System, ['system' => Snapshots::system()]))), Component::System)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertEquals(new SubNav('Stack', [
            new SubNavItem('All components', 'stack', icon: 'stack'),
            new SubNavItem('Server', 'stack/system', true, icon: 'system'),
        ], 'web-1'), $blocks[0]->content);
    }

    public function testAComponentThatIsNotPartOfTheStackSaysSo(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], ['symfony' => 'No kernel was found']);

        $blocks = $this->screen(new FakeStackReader($state), Component::Symfony)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::NOTICE], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertEquals(new SubNav('Stack', [
            new SubNavItem('All components', 'stack', icon: 'stack'),
            new SubNavItem('Symfony', 'stack/symfony', true, new Mark('unknown', 'Unknown'), icon: 'symfony'),
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

    public function testTheConnectionAskedForIsShown(): void
    {
        $reader = new FakeStackReader(self::databases(['main' => 'db1:3306', 'reports' => 'db1:3306'], 'reports'));

        $blocks = $this->screen($reader, Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW, instance: 'reports'));

        self::assertSame(['reports'], $reader->instances);
        self::assertSame([Block::SUBNAV, Block::INSTANCES, Block::COMPONENTS, Block::CHECKS], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertInstanceOf(ComponentCards::class, $blocks[2]->content);
        self::assertSame('mysql.reports', $blocks[2]->content->cards[0]->id);
    }

    public function testWithoutOneTheFirstIsShown(): void
    {
        $reader = new FakeStackReader(self::databases(['main' => 'db1:3306', 'reports' => 'db1:3306'], 'main'));

        $blocks = $this->screen($reader, Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([null], $reader->instances);
        self::assertInstanceOf(ComponentCards::class, $blocks[2]->content);
        self::assertSame('mysql.main', $blocks[2]->content->cards[0]->id);
    }

    public function testAnUnknownConnectionSaysSoAndOffersTheOthers(): void
    {
        $reader = new FakeStackReader(self::databases(['main' => 'db1:3306', 'reports' => 'db1:3306']));

        $blocks = $this->screen($reader, Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW, instance: '<b>nope</b>'));

        self::assertSame([Block::SUBNAV, Block::NOTICE, Block::INSTANCES], array_map(static fn(Block $block): string => $block->type, $blocks));
        self::assertInstanceOf(Notice::class, $blocks[1]->content);
        self::assertSame('No connection named "<b>nope</b>" is read', $blocks[1]->content->title);
        self::assertEquals(new InstanceNav([new InstanceNavRow('Databases', [
            new InstanceNavItem('main', 'stack/mysql', ['connection' => 'main'], false, 'main'),
            new InstanceNavItem('reports', 'stack/mysql', ['connection' => 'reports'], false, 'reports'),
        ])]), $blocks[2]->content);
    }

    public function testALocalComponentIgnoresTheConnectionAskedFor(): void
    {
        $reader = new FakeStackReader(self::stack(Snapshots::of(Component::Php, ['php' => Snapshots::php()])));

        $blocks = $this->screen($reader, Component::Php)->blocks(new ScreenRequest(Snapshots::NOW, instance: 'reports'));

        self::assertSame([null], $reader->instances);
        self::assertSame([Block::SUBNAV, Block::COMPONENTS, Block::CHECKS], array_slice(array_map(static fn(Block $block): string => $block->type, $blocks), 0, 3));
    }

    public function testALongUnknownConnectionNameIsCut(): void
    {
        $reader = new FakeStackReader(self::databases(['main' => 'db1:3306', 'reports' => 'db1:3306']));

        $blocks = $this->screen($reader, Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW, instance: str_repeat('é', 64) . str_repeat('x', 1000)));

        self::assertInstanceOf(Notice::class, $blocks[1]->content);
        self::assertSame('No connection named "' . str_repeat('é', 64) . '…" is read', $blocks[1]->content->title);

        $blocks = $this->screen($reader, Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW, instance: str_repeat('x', 64)));

        self::assertInstanceOf(Notice::class, $blocks[1]->content);
        self::assertSame('No connection named "' . str_repeat('x', 64) . '" is read', $blocks[1]->content->title);
    }

    public function testASingleConnectionHasNoSelector(): void
    {
        $blocks = $this->screen(new FakeStackReader(self::databases(['main' => 'db1:3306'], 'main')), Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertSame([Block::SUBNAV, Block::COMPONENTS, Block::CHECKS], array_map(static fn(Block $block): string => $block->type, $blocks));
    }

    public function testTwoDatabasesOfOneServerHaveOnlyADatabasesRow(): void
    {
        $blocks = $this->screen(new FakeStackReader(self::databases(['main' => 'db1:3306', 'reports' => 'db1:3306'], 'reports')), Component::Mysql)
            ->blocks(new ScreenRequest(Snapshots::NOW, instance: 'reports'));

        self::assertEquals(new InstanceNav([new InstanceNavRow('Databases', [
            new InstanceNavItem('main', 'stack/mysql', ['connection' => 'main'], false, 'main'),
            new InstanceNavItem('reports', 'stack/mysql', ['connection' => 'reports'], true, 'reports', new Mark('ok', 'Healthy')),
        ])]), $blocks[1]->content);
    }

    public function testTwoServersHaveAServersRow(): void
    {
        $servers = ['main' => 'db1:3306', 'reports' => 'db1:3306', 'archive' => 'db2:3306'];

        $archive = $this->screen(new FakeStackReader(self::databases($servers, 'archive')), Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW, instance: 'archive'));

        self::assertEquals(new InstanceNav([new InstanceNavRow('Servers', [
            new InstanceNavItem('main', 'stack/mysql', ['connection' => 'main'], false, 'db1:3306'),
            new InstanceNavItem('archive', 'stack/mysql', ['connection' => 'archive'], true, 'db2:3306', new Mark('ok', 'Healthy')),
        ])]), $archive[1]->content);

        $reports = $this->screen(new FakeStackReader(self::databases($servers, 'reports')), Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW, instance: 'reports'));

        self::assertEquals(new InstanceNav([
            new InstanceNavRow('Servers', [
                new InstanceNavItem('main', 'stack/mysql', ['connection' => 'main'], true, 'db1:3306'),
                new InstanceNavItem('archive', 'stack/mysql', ['connection' => 'archive'], false, 'db2:3306'),
            ]),
            new InstanceNavRow('Databases', [
                new InstanceNavItem('main', 'stack/mysql', ['connection' => 'main'], false, 'main'),
                new InstanceNavItem('reports', 'stack/mysql', ['connection' => 'reports'], true, 'reports', new Mark('ok', 'Healthy')),
            ]),
        ]), $reports[1]->content);
    }

    public function testAConnectionInErrorCarriesAMarkWithAWord(): void
    {
        $state = Snapshots::state(['mysql' => [
            self::database('main', 'db1:3306', true),
            new InstanceState('broken', null, 'Unknown Doctrine connection "broken".', null),
        ]]);

        $blocks = $this->screen(new FakeStackReader($state), Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW));

        self::assertInstanceOf(InstanceNav::class, $blocks[1]->content);
        $broken = $blocks[1]->content->rows[0]->items[1];
        self::assertSame('broken', $broken->label);
        self::assertNull($broken->detail);
        self::assertNotNull($broken->mark);
        self::assertSame('warn', $broken->mark->tone);
        self::assertNotSame('', $broken->mark->label);
    }

    public function testTheChecksShownAreThoseOfTheConnection(): void
    {
        $full = DatabasePayloads::mysqlStatus();
        $full['Threads_connected'] = '99';
        $state = Snapshots::state(['mysql' => [
            self::database('main', 'db1:3306', true),
            new InstanceState('reports', 'reports', null, Snapshots::of(Component::Mysql, ['mysql' => $full, 'mysql.variables' => DatabasePayloads::mysqlVariables()]), new Server('db1:3306', 'db1:3306'), 1),
        ]]);
        $checks = fn(?string $instance): array => array_map(
            static fn(CheckLine $line): string => $line->id . ' ' . $line->where,
            $this->checkList($this->screen(new FakeStackReader($state), Component::Mysql)->blocks(new ScreenRequest(Snapshots::NOW, instance: $instance)))->lines,
        );

        self::assertSame([], $checks('main'));
        self::assertSame(['mysql.connections MySQL · reports'], $checks('reports'));
    }

    public function testThePostgresqlDetailHasItsSelectorOfConnections(): void
    {
        $snapshot = Snapshots::of(Component::Postgresql, ['postgresql' => DatabasePayloads::postgresqlActivity(), 'postgresql.settings' => DatabasePayloads::postgresqlSettings()]);
        $connection = static fn(string $name, string $server, bool $read): InstanceState => new InstanceState($name, $name . '_db', null, $read ? $snapshot : null, new Server($server, $server));
        $state = Snapshots::state(['postgresql' => [
            $connection('main', 'db1:5432', false),
            $connection('reports', 'db1:5432', true),
            $connection('archive', 'db2:5432', false),
        ]]);

        $blocks = $this->screen(new FakeStackReader($state), Component::Postgresql)->blocks(new ScreenRequest(Snapshots::NOW, instance: 'reports'));

        self::assertSame([Block::SUBNAV, Block::INSTANCES, Block::COMPONENTS, Block::CHECKS], array_map(static fn(Block $block): string => $block->type, $blocks));
        $nav = $blocks[1]->content;
        self::assertInstanceOf(InstanceNav::class, $nav);
        self::assertSame(['Connections'], array_map(static fn(InstanceNavRow $row): string => $row->label, $nav->rows));
        self::assertSame(
            [['main', 'main_db', false], ['reports', 'reports_db', true], ['archive', 'archive_db', false]],
            array_map(static fn(InstanceNavItem $item): array => [$item->label, $item->detail, $item->current], $nav->rows[0]->items),
        );
        self::assertSame(['connection' => 'archive'], $nav->rows[0]->items[2]->query);
        self::assertSame('stack/postgresql', $nav->rows[0]->items[2]->path);
        self::assertInstanceOf(ComponentCards::class, $blocks[2]->content);
        self::assertSame('postgresql.reports', $blocks[2]->content->cards[0]->id);
        self::assertSame('db1:5432', $blocks[2]->content->cards[0]->note);
    }

    public function testTheRedisDetailHasItsSelectorOfServers(): void
    {
        $snapshot = Snapshots::of(Component::Redis, ['redis' => RedisPayloads::kept()]);
        $server = static fn(string $address): InstanceState => new InstanceState($address, null, null, $snapshot, new Server($address, $address));
        $state = Snapshots::state(['redis' => [$server('cache:6379'), $server('sessions:6379')]]);

        $blocks = $this->screen(new FakeStackReader($state), Component::Redis)->blocks(new ScreenRequest(Snapshots::NOW, instance: 'sessions:6379'));

        self::assertSame([Block::SUBNAV, Block::INSTANCES, Block::COMPONENTS, Block::CHECKS], array_map(static fn(Block $block): string => $block->type, $blocks));
        $nav = $blocks[1]->content;
        self::assertInstanceOf(InstanceNav::class, $nav);
        self::assertSame(['Connections'], array_map(static fn(InstanceNavRow $row): string => $row->label, $nav->rows), 'one row, under the word every component not grouped by server uses');
        self::assertSame(
            [['cache:6379', null, false], ['sessions:6379', null, true]],
            array_map(static fn(InstanceNavItem $item): array => [$item->label, $item->detail, $item->current], $nav->rows[0]->items),
        );
        self::assertSame(['connection' => 'cache:6379'], $nav->rows[0]->items[0]->query);
        self::assertSame('stack/redis', $nav->rows[0]->items[0]->path);
        self::assertInstanceOf(ComponentCards::class, $blocks[2]->content);
        self::assertSame('redis.sessions:6379', $blocks[2]->content->cards[0]->id);
        self::assertNull($blocks[2]->content->cards[0]->note, 'its id is its address');
    }

    /**
     * @param array<string, string> $servers the server key of each connection, whose database is named after it
     */
    private static function databases(array $servers, ?string $read = null): StackState
    {
        $siblings = array_count_values($servers);

        return Snapshots::state(['mysql' => array_map(
            static fn(string $name, string $server): InstanceState => self::database($name, $server, $name === $read, $siblings[$server] - 1),
            array_keys($servers),
            array_values($servers),
        )]);
    }

    private static function database(string $name, string $server, bool $read, int $siblings = 0): InstanceState
    {
        $snapshot = $read ? Snapshots::of(Component::Mysql, ['mysql' => DatabasePayloads::mysqlStatus(), 'mysql.variables' => DatabasePayloads::mysqlVariables()]) : null;

        return new InstanceState($name, $name, null, $snapshot, new Server($server, $server), $siblings);
    }

    /**
     * @param list<Block> $blocks
     */
    private function checkList(array $blocks): CheckList
    {
        foreach ($blocks as $block) {
            if ($block->content instanceof CheckList) {
                return $block->content;
            }
        }

        throw new \LogicException('No check list.');
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
            default => new class implements DetailInterface {
                public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
                {
                    return [];
                }
            },
        };

        return new ComponentScreen($component, $detail, new ServiceLocator([
            StackReaderInterface::class => static fn(): StackReaderInterface => $reader,
            StackChecks::class => fn(): StackChecks => new StackChecks([], false, $this->clock),
            StackSummary::class => static fn(): StackSummary => $summary,
        ]), new CardBuilder($this->clock), $this->clock);
    }
}
