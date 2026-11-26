<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Server;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\OverviewGlance;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\FakeStackReader;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class OverviewGlanceTest extends TestCase
{
    private MockClock $clock;

    private CountingCachePool $pool;

    /** @var list<string> the services asked to the locator of the glance */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('@' . Snapshots::NOW);
        $this->pool = new CountingCachePool();
    }

    public function testItGivesOneLinkedCardPerInstanceRead(): void
    {
        $state = Snapshots::state([
            'php' => [new InstanceState(null, null, null, Snapshots::of(Component::Php, ['php' => Snapshots::php()]))],
            'postgresql' => [
                self::postgresql('main', true),
                self::postgresql('reports', true),
                self::postgresql('spare', false),
            ],
        ]);

        $glance = $this->glance(new FakeStackReader($state))->glance();

        self::assertSame(['php', 'postgresql.main', 'postgresql.reports'], array_map(static fn(ComponentCard $card): string => $card->id, $glance->cards));
        self::assertSame(['stack/php', 'stack/postgresql', 'stack/postgresql'], array_map(static fn(ComponentCard $card): ?string => $card->path, $glance->cards));
        self::assertSame([[], ['connection' => 'main'], ['connection' => 'reports']], array_map(static fn(ComponentCard $card): array => $card->query, $glance->cards));
        self::assertSame('1 more connection', $glance->more);
        self::assertNull($glance->notice);
    }

    public function testItsLinesAreTheRaisedChecksMostSevereFirstUnderStack(): void
    {
        $glance = $this->glance(new FakeStackReader(self::troubled()))->glance();

        self::assertSame(
            [
                ['symfony.debug_in_prod', 'bad', 'Critical', 'Stack · Symfony', 'stack/symfony', [], null],
                ['system.disk', 'warn', 'Warning', 'Stack · Server', 'stack/system', [], '1 h ago'],
                ['php.fpm_max_children', 'info', 'Info', 'Stack · PHP', 'stack/php', [], null],
            ],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->mark->tone, $line->mark->label, $line->where, $line->path, $line->query, $line->age], $glance->lines),
        );
        self::assertSame('Debug mode is on in the prod environment', $glance->lines[0]->sentence);
        self::assertStringStartsWith('Debug mode slows down every request', $glance->lines[0]->explanation);
    }

    public function testALineOfANamedInstanceSaysWhichOneUnderStack(): void
    {
        $status = DatabasePayloads::mysqlStatus();
        $status['Threads_connected'] = '99';
        $snapshot = Snapshots::of(Component::Mysql, ['mysql' => $status, 'mysql.variables' => DatabasePayloads::mysqlVariables()]);
        $state = Snapshots::state(['mysql' => [new InstanceState('default', 'app', null, $snapshot, new Server('db1:3306', 'db1:3306'))]]);

        $lines = $this->glance(new FakeStackReader($state))->glance()->lines;

        self::assertSame(
            [['mysql.connections', 'Stack · MySQL · default', 'stack/mysql', ['connection' => 'default']]],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->where, $line->path, $line->query], $lines),
        );
    }

    public function testAnEngineFailureGivesANoticeAndNothingElse(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], [], 'Cannot reach redis://cache:6379: Connection refused');

        $glance = $this->glance(new FakeStackReader($state))->glance();

        self::assertEquals(new StackGlance(notice: new Notice('bad', 'The stack could not be read', 'Cannot reach redis://cache:6379: Connection refused')), $glance);
    }

    public function testACacheOutOfOrderKeepsTheCardsReadInProcess(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::OutOfOrder, Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => Snapshots::php()], ['eol.php' => ResultState::Pending]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]),
        )->components, []);

        $glance = $this->glance(new FakeStackReader($state))->glance();

        self::assertEquals(new Notice('warn', 'The live stack needs a working cache', 'Only what PHP can read in its own process is shown. Check the cache.app pool of the application.'), $glance->notice);
        self::assertSame(['php', 'symfony'], array_map(static fn(ComponentCard $card): string => $card->id, $glance->cards));
        self::assertSame([false, false], array_map(static fn(ComponentCard $card): bool => $card->reading, $glance->cards));
        self::assertSame('—', $glance->cards[1]->vitals[2]->value);
        self::assertNull($this->summary()->last(), 'a cache out of order keeps no summary');
    }

    public function testNoComponentGivesNeitherCardNorNotice(): void
    {
        $state = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], ['php' => 'turned off']);

        self::assertEquals(new StackGlance(), $this->glance(new FakeStackReader($state))->glance());
    }

    public function testItRemembersTheSummaryAtEveryRead(): void
    {
        $this->glance(new FakeStackReader(self::troubled()))->glance();

        self::assertSame(
            ['at' => Snapshots::NOW, 'critical' => 1, 'warning' => 1, 'components' => ['system' => 'warning', 'php' => 'healthy', 'symfony' => 'critical']],
            $this->summary()->last(),
        );

        $this->clock->sleep(30);
        $this->glance(new FakeStackReader(self::reading()))->glance();

        self::assertSame(
            ['at' => Snapshots::NOW + 30, 'critical' => 0, 'warning' => 0, 'components' => ['php' => 'healthy', 'symfony' => 'healthy']],
            $this->summary()->last(),
            'what is still read does not keep the summary of an earlier read',
        );
    }

    public function testItDoesNotRememberAFailedEngine(): void
    {
        $this->glance(new FakeStackReader(self::troubled()))->glance();
        $remembered = $this->summary()->last();
        $this->clock->sleep(30);
        $this->pool->reset();

        $this->glance(new FakeStackReader(new StackState('web-1', Snapshots::NOW, CacheState::Working, [], [], 'Connection refused')))->glance();

        self::assertNotNull($remembered);
        self::assertSame([], $this->pool->calls, 'nothing is written');
        self::assertSame($remembered, $this->summary()->last());
    }

    public function testBuildingItBuildsNoReader(): void
    {
        $reader = new FakeStackReader(self::troubled());

        $glance = $this->glance($reader);

        self::assertSame([], $this->asked);
        self::assertSame([], $reader->calls);

        $glance->glance();

        self::assertSame([StackReaderInterface::class, StackChecks::class, StackSummary::class], $this->asked);
    }

    public function testItAlwaysReadsAsAPageAndNeverTheQueues(): void
    {
        $reader = new FakeStackReader(self::troubled());
        $glance = $this->glance($reader);

        $glance->glance();
        $glance->glance();

        self::assertSame(['overview Page', 'overview Page'], $reader->calls);
    }

    /**
     * A disk 90 % full read an hour ago, debug mode in prod, the Info check on PHP-FPM.
     */
    private static function troubled(): StackState
    {
        $system = Snapshots::system();
        $system['disk']['free'] = 10_000_000_000;

        return Snapshots::stack(
            Snapshots::of(Component::System, ['system' => $system], ['system' => ResultState::Stale]),
            Snapshots::of(Component::Php, ['php' => Snapshots::php()]),
            Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true] + Snapshots::symfony()]),
        );
    }

    /**
     * PHP read, the Flex recipes of Symfony still read.
     */
    private static function reading(): StackState
    {
        $php = Snapshots::php();
        unset($php['fpm']);

        return Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => $php]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]),
        );
    }

    private static function postgresql(string $name, bool $read): InstanceState
    {
        $snapshot = $read ? Snapshots::of(Component::Postgresql, ['postgresql' => DatabasePayloads::postgresqlActivity(), 'postgresql.settings' => DatabasePayloads::postgresqlSettings()]) : null;

        return new InstanceState($name, $name, null, $snapshot, new Server('db1:5432', 'db1:5432'));
    }

    private function summary(): StackSummary
    {
        return new StackSummary(new ServiceLocator(['cache' => fn(): CountingCachePool => $this->pool]), new WebNode('web-1'), $this->clock);
    }

    private function glance(StackReaderInterface $reader): OverviewGlance
    {
        $summary = $this->summary();
        $services = [
            StackReaderInterface::class => static fn(): StackReaderInterface => $reader,
            StackChecks::class => fn(): StackChecks => new StackChecks([], false, $this->clock),
            StackSummary::class => static fn(): StackSummary => $summary,
        ];

        return new OverviewGlance(new ServiceLocator(array_map(fn(\Closure $factory): \Closure => function () use ($factory): object {
            $service = $factory();
            $this->asked[] = $service instanceof StackReaderInterface ? StackReaderInterface::class : $service::class;

            return $service;
        }, $services)), new CardBuilder($this->clock), $this->clock);
    }
}
