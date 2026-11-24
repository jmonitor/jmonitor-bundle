<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Collector\RedisInfoCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\RedisLink;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Server;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\RedisDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeRedis;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RedisPayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class RedisDetailTest extends TestCase
{
    use DescribesSections;

    private const LIMIT = 2_147_483_648;

    private const PREVIOUS = ['stats' => [
        'total_commands_processed' => 840_000,
        'keyspace_hits' => 8_950,
        'keyspace_misses' => 1_000,
        'evicted_keys' => 4,
        'rejected_connections' => 0,
    ]];

    public function testAServerShowsItsSixSectionsInOrder(): void
    {
        $data = self::data(['memory' => ['max_memory' => self::LIMIT]]);
        $snapshot = self::snapshot($data, Snapshots::delta(self::PREVIOUS, $data));

        $blocks = (new RedisDetail())->sections(self::server($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['gauges', 'Usage', ['Memory' => self::nb('69.8 %'), 'Clients' => '12']],
            ['facts', 'Traffic', [
                'Commands per second' => self::nb('1,000 cmd/s'),
                'Hit rate' => self::nb('95.2 %'),
                'Evicted keys' => '3 [Info]',
                'Refused connections' => '0',
            ]],
            ['facts', 'Memory', [
                'Used' => self::nb('1.4 GB'),
                'Peak' => self::nb('1.5 GB'),
                'Limit' => self::nb('2 GB'),
                'Policy' => 'allkeys-lru',
            ]],
            ['facts', 'Persistence', [
                'Last RDB snapshot' => 'ok',
                'Last saved' => '5' . self::NBSP . 'min ago',
                'Changes not saved' => '42',
                'AOF' => 'off',
            ]],
            ['table', 'Keyspace', ['db0 | 1,500 | 1,200', 'db1 | 10 | 0']],
            ['facts', 'Server', [
                'Version' => 'Redis 7.2.4 [Security fixes only]',
                'Mode' => 'standalone',
                'Role' => 'master',
                'Uptime' => self::nb('4 d 4 h'),
            ]],
        ], self::describe($blocks));

        $hints = self::hints($blocks);
        self::assertSame(self::nb('1.4 GB') . ' of ' . self::nb('2 GB'), $hints['Usage / Memory']);
        self::assertSame('connected', $hints['Usage / Clients']);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Traffic / Commands per second']);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Traffic / Hit rate']);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Traffic / Evicted keys']);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Traffic / Refused connections']);
        self::assertSame('since the server started', $hints['Memory / Peak']);
        self::assertSame('any key may be evicted at the limit', $hints['Memory / Policy']);

        $keyspace = $blocks[4]->content;
        self::assertInstanceOf(Table::class, $keyspace);
        self::assertSame(['Database', 'Keys', 'With an expiry'], array_map(static fn(Column $column): string => $column->label, $keyspace->columns));
    }

    public function testWithoutASecondReadingTheWindowsWait(): void
    {
        $blocks = (new RedisDetail())->sections(self::server(self::snapshot(self::data())), [], Snapshots::NOW);
        $traffic = self::describe($blocks)[1][2];
        $hints = self::hints($blocks);

        self::assertSame(['Commands per second' => '—', 'Hit rate' => self::nb('90.4 %'), 'Evicted keys' => '—', 'Refused connections' => '—'], $traffic);
        self::assertSame('at the next refresh', $hints['Traffic / Commands per second']);
        self::assertSame('since the server started', $hints['Traffic / Hit rate']);
        self::assertSame('at the next refresh', $hints['Traffic / Evicted keys']);
        self::assertSame('at the next refresh', $hints['Traffic / Refused connections']);
    }

    public function testARateWithoutSecondSampleIsOnlyPromisedWhileTheServerAnswers(): void
    {
        $dead = new ComponentSnapshot(Component::Redis, [
            'redis' => new CollectorResult('redis', self::data(), ResultState::Stale, 'The connection is refused, cannot be opened, or gets no answer in time.', Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ]);

        $hints = self::hints((new RedisDetail())->sections(self::server($dead), [], Snapshots::NOW));

        self::assertNull($hints['Traffic / Commands per second']);
        self::assertNull($hints['Traffic / Evicted keys']);
        self::assertNull($hints['Traffic / Refused connections']);
        self::assertSame('since the server started', $hints['Traffic / Hit rate']);
    }

    public function testItsCountsAreWrittenLikeThoseOfATable(): void
    {
        $data = self::data([
            'clients' => ['connected' => 12_000],
            'persistence' => ['rdb_changes_since_last_save' => 107_530],
            'stats' => ['evicted_keys' => 1_797, 'rejected_connections' => 2_500],
        ]);
        $snapshot = self::snapshot($data, Snapshots::delta(self::PREVIOUS, $data));

        $blocks = (new RedisDetail())->sections(self::server($snapshot), [], Snapshots::NOW);

        self::assertSame('12,000', self::describe($blocks)[0][2]['Clients']);
        self::assertSame('1,793', self::describe($blocks)[1][2]['Evicted keys']);
        self::assertSame('2,500', self::describe($blocks)[1][2]['Refused connections']);
        self::assertSame('107,530', self::describe($blocks)[3][2]['Changes not saved']);
    }

    public function testWithoutALimitTheMemoryIsAFact(): void
    {
        $blocks = (new RedisDetail())->sections(self::server(self::snapshot(self::data())), [], Snapshots::NOW);
        $usage = $blocks[0]->content;

        self::assertInstanceOf(Gauges::class, $usage);
        self::assertNull($usage->gauges[0]->percent, 'nothing to draw');
        self::assertSame(self::nb('1.4 GB'), $usage->gauges[0]->value);
        self::assertSame('no limit', $usage->gauges[0]->hint);
        self::assertSame('no limit', self::describe($blocks)[2][2]['Limit']);
        self::assertNull(self::hints($blocks)['Memory / Policy'], 'a policy only acts at a limit');
    }

    public function testThePolicySaysWhatHappensAtTheLimit(): void
    {
        $hint = static fn(string $policy): ?string => self::hints((new RedisDetail())->sections(
            self::server(self::snapshot(self::data(['memory' => ['max_memory' => self::LIMIT, 'max_memory_policy' => $policy]]))),
            [],
            Snapshots::NOW,
        ))['Memory / Policy'];

        self::assertSame('writes are refused at the limit', $hint('noeviction'));
        self::assertSame('any key may be evicted at the limit', $hint('allkeys-lru'));
        self::assertSame('any key may be evicted at the limit', $hint('allkeys-random'));
        self::assertSame('only keys with an expiry are evicted at the limit; without any, writes are refused', $hint('volatile-lru'));
        self::assertSame('only keys with an expiry are evicted at the limit; without any, writes are refused', $hint('volatile-ttl'));
        self::assertNull($hint('unknown-policy'));
    }

    public function testAFewLookupsShowTheRateSinceTheServerStarted(): void
    {
        $data = self::data(['stats' => ['keyspace_hits' => 9_950, 'keyspace_misses' => 1_053]]);
        $snapshot = self::snapshot($data, Snapshots::delta(self::data(), $data));

        $blocks = (new RedisDetail())->sections(self::server($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(self::nb('90.4 %'), self::describe($blocks)[1][2]['Hit rate'], 'not the 0 % of three lookups missed');
        self::assertSame('since the server started', self::hints($blocks)['Traffic / Hit rate']);
    }

    public function testTheModeOfValkey8IsShown(): void
    {
        $kept = (new RedisInfoCollector(new RedisLink(static fn(): FakeRedis => new FakeRedis(RedisPayloads::valkey8()))))->collect();

        $described = self::describe((new RedisDetail())->sections(self::server(self::snapshot($kept)), [], Snapshots::NOW));

        self::assertSame('standalone', $described[5][2]['Mode']);
        self::assertSame('Valkey 8.1.1', $described[5][2]['Version']);
    }

    public function testAFactCarriesTheMarkOfItsCheck(): void
    {
        $data = self::data([
            'memory' => ['used' => 2_126_008_812, 'max_memory' => self::LIMIT, 'max_memory_policy' => 'noeviction'],
            'persistence' => ['rdb_last_bgsave_status' => 'err', 'aof_enabled' => 1, 'aof_last_bgrewrite_status' => 'err'],
            'stats' => ['keyspace_hits' => 9_140, 'keyspace_misses' => 1_810, 'rejected_connections' => 2],
        ]);
        $snapshot = self::snapshot($data, Snapshots::delta(self::PREVIOUS, $data));

        $described = self::describe((new RedisDetail())->sections(self::server($snapshot), self::findings($snapshot), Snapshots::NOW));

        self::assertSame(self::nb('99 %') . ' [Critical]', $described[0][2]['Memory']);
        self::assertSame(self::nb('19 %') . ' [Info]', $described[1][2]['Hit rate']);
        self::assertSame('2 [Warning]', $described[1][2]['Refused connections']);
        self::assertSame('noeviction', $described[2][2]['Policy']);
        self::assertSame('err [Critical]', $described[3][2]['Last RDB snapshot']);
        self::assertSame('on', $described[3][2]['AOF']);
        self::assertSame('err [Critical]', $described[3][2]['Last AOF rewrite']);
    }

    public function testUnderAnEvictionPolicyAFullMemoryCarriesNoMark(): void
    {
        $snapshot = self::snapshot(self::data(['memory' => ['used' => 2_126_008_812, 'max_memory' => self::LIMIT, 'max_memory_policy' => 'allkeys-lru']]));

        $blocks = (new RedisDetail())->sections(self::server($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(self::nb('99 %'), self::describe($blocks)[0][2]['Memory'], 'a full cache that evicts is in its usual state');
    }

    public function testTheFigureOfAFactIsTheOneOfItsSentence(): void
    {
        $data = self::data(['memory' => ['used' => 2_000_000_000, 'max_memory' => self::LIMIT, 'max_memory_policy' => 'noeviction']]);
        $snapshot = self::snapshot($data);
        $findings = self::findings($snapshot);
        $sentences = array_column(array_map(static fn(Finding $finding): array => [$finding->id, $finding->sentence], $findings), 1, 0);

        $blocks = (new RedisDetail())->sections(self::server($snapshot), $findings, Snapshots::NOW);
        $memory = $blocks[0]->content;

        self::assertInstanceOf(Gauges::class, $memory);
        self::assertStringContainsString(' ' . $memory->gauges[0]->value . ' ', $sentences['redis.memory']);
        self::assertStringContainsString('(' . $memory->gauges[0]->hint . ')', $sentences['redis.memory']);
    }

    public function testASnapshotOrARewriteInProgressIsTold(): void
    {
        $snapshot = self::snapshot(self::data(['persistence' => [
            'rdb_bgsave_in_progress' => 1,
            'rdb_last_bgsave_status' => 'err',
            'aof_enabled' => 1,
            'aof_rewrite_in_progress' => 1,
            'aof_last_bgrewrite_status' => 'err',
        ]]));

        $described = self::describe((new RedisDetail())->sections(self::server($snapshot), self::findings($snapshot), Snapshots::NOW));

        self::assertSame('in progress', $described[3][2]['Last RDB snapshot']);
        self::assertSame('in progress', $described[3][2]['Last AOF rewrite']);
    }

    public function testValkeyShowsItsOwnVersion(): void
    {
        $snapshot = self::snapshot(self::data(['server' => ['name' => 'valkey', 'valkey_version' => '8.0.1']]));

        $described = self::describe((new RedisDetail())->sections(self::server($snapshot), self::findings($snapshot), Snapshots::NOW));

        self::assertSame('Valkey 8.0.1', $described[5][2]['Version']);
    }

    public function testAnEmptyServerSaysSo(): void
    {
        $blocks = (new RedisDetail())->sections(self::server(self::snapshot(self::data(['databases' => null]))), [], Snapshots::NOW);
        $keyspace = $blocks[4];

        self::assertSame([BlockState::Empty, Block::TABLE, 'No key in this server'], [$keyspace->state, $keyspace->type, $keyspace->title]);
        self::assertSame(['Usage', 'Traffic', 'Memory', 'Persistence', BlockState::Empty->value, 'Server'], self::titles($blocks));
    }

    public function testTheSectionsWaitForTheirCollector(): void
    {
        $snapshot = Snapshots::of(Component::Redis, [], ['redis' => ResultState::Pending]);

        self::assertSame([[BlockState::Reading->value, Block::GAUGES]], self::describe((new RedisDetail())->sections(self::server($snapshot), [], Snapshots::NOW)));
        self::assertSame([], (new RedisDetail())->sections(self::server($snapshot), [], Snapshots::NOW, false));
    }

    public function testAnUnreachableServerIsToldOnce(): void
    {
        $reason = 'The connection is refused, cannot be opened, or gets no answer in time.';
        $dead = new ComponentSnapshot(Component::Redis, [
            'redis' => new CollectorResult('redis', [], ResultState::Failed, $reason, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ]);
        $read = new ComponentSnapshot(Component::Redis, [
            'redis' => new CollectorResult('redis', self::data(), ResultState::Stale, $reason, Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ]);

        self::assertSame([], (new RedisDetail())->sections(self::server($dead), [], Snapshots::NOW), 'the card tells why');

        $titles = self::titles((new RedisDetail())->sections(self::server($read), [], Snapshots::NOW));

        self::assertSame(['notice: Could not be read again: ' . $reason, 'Usage', 'Traffic', 'Memory', 'Persistence', 'Keyspace', 'Server'], $titles);
        self::assertSame([], (new RedisDetail())->sections(new InstanceState('main', null, 'The DSN of this connection cannot be read.', null), [], Snapshots::NOW));
    }

    public function testEverythingAServerSaysIsEscaped(): void
    {
        $snapshot = self::snapshot(self::data([
            'server' => ['mode' => '<b>mode</b>', 'name' => 'valkey', 'valkey_version' => '<s>8</s>'],
            'memory' => ['max_memory' => self::LIMIT, 'max_memory_policy' => '<u>policy</u>'],
            'persistence' => ['rdb_last_bgsave_status' => '<em>err</em>'],
            'replication' => ['role' => '<i>role</i>'],
            'databases' => ['<script>alert(1)</script>' => ['keys' => '1', 'expires' => '0']],
        ]));

        $html = self::render((new RedisDetail())->sections(self::server($snapshot), self::findings($snapshot), Snapshots::NOW));

        foreach (['<script>', '<b>mode', '<u>policy', '<em>err', '<i>role', '<s>8'] as $raw) {
            self::assertStringNotContainsString($raw, $html);
        }
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;mode&lt;/b&gt;', $html);
        self::assertStringContainsString('&lt;u&gt;policy&lt;/u&gt;', $html);
        self::assertStringContainsString('&lt;em&gt;err&lt;/em&gt;', $html);
        self::assertStringContainsString('&lt;i&gt;role&lt;/i&gt;', $html);
        self::assertStringContainsString('Valkey &lt;s&gt;8&lt;/s&gt;', $html);
    }

    /**
     * @param list<Block> $blocks
     */
    private static function render(array $blocks): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 4) . '/templates', 'Jmonitor');
        $twig = new Environment($loader, ['autoescape' => false, 'strict_variables' => true]);
        $twig->addExtension(new JmonitorExtension());

        return implode('', array_map(
            static fn(Block $block): string => $twig->render('@Jmonitor/dashboard/block/' . ($block->state === BlockState::Ready ? $block->type : 'state') . '.html.twig', ['block' => $block]),
            $blocks,
        ));
    }

    /**
     * A Redis 7.2 that saved its data five minutes ago, the values given replaced.
     *
     * @param array<string, mixed> $replaced replacing what the stack keeps of the server, recursively; null drops a key
     *
     * @return array<string, mixed>
     */
    private static function data(array $replaced = []): array
    {
        $kept = RedisPayloads::kept();
        $kept['persistence']['rdb_last_save_time'] = Snapshots::NOW - 300;

        return array_filter(array_replace_recursive($kept, $replaced), static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function snapshot(array $data, ?Delta $delta = null): ComponentSnapshot
    {
        return Snapshots::of(
            Component::Redis,
            ['redis' => $data, 'eol.redis' => RedisPayloads::redisCycles(), 'eol.valkey' => RedisPayloads::valkeyCycles()],
            deltas: $delta === null ? [] : ['redis' => $delta],
        );
    }

    private static function server(ComponentSnapshot $snapshot): InstanceState
    {
        return new InstanceState('cache', null, null, $snapshot, new Server('cache:6379', 'cache:6379'));
    }
}
