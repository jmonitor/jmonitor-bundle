<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PostgresqlDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PostgresqlDetailTest extends TestCase
{
    use DescribesSections;

    private const SECTIONS = ['Usage', 'Traffic', 'Cache', 'Sessions', 'Blocked sessions', 'Tables', 'Settings', 'Slowest queries'];

    public function testADatabaseShowsItsSectionsInOrder(): void
    {
        $snapshot = self::snapshot();

        $blocks = (new PostgresqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['gauges', 'Usage', ['Server connections' => self::nb('37 %'), 'Database connections' => '7']],
            ['facts', 'Traffic', [
                'Transactions per second' => '—',
                'Rollbacks' => self::nb('0.1 %'),
                'Rows written per second' => '—',
            ]],
            ['facts', 'Cache', [
                'Cache hit rate' => self::nb('99 %'),
                'Disk reads per second' => '—',
                'Temporary files' => '3',
            ]],
            ['facts', 'Sessions', [
                'Waiting for a lock' => '1 [Warning]',
                'Oldest transaction' => self::nb('5 min 40 s'),
                'Idle in a transaction' => '1 [Warning]',
            ]],
            ['table', 'Blocked sessions', ['4242 | ' . self::nb('45 s') . ' | 4100 | idle in transaction']],
            ['facts', 'Tables', [
                'Schema' => 'public',
                'Tables' => '12',
                'Dead rows' => self::nb('1.5 %'),
                'Database size' => self::nb('80 MB'),
                'Table size' => self::nb('60 MB'),
                'Index size' => self::nb('20 MB'),
            ]],
            ['facts', 'Settings', [
                'Version' => 'PostgreSQL 17.2',
                'max_connections' => '100',
                'shared_buffers' => self::nb('128 MB'),
                'work_mem' => self::nb('4 MB'),
                'autovacuum' => 'on',
                'track_counts' => 'on',
                'Time zone' => 'Etc/UTC',
            ]],
            ['table', 'Slowest queries', ['SELECT * FROM customer WHERE email = $1 | 120 | ' . self::nb('300 ms') . ' | ' . self::nb('1.2 s') . ' | ' . self::nb('36 s')]],
        ], self::describe($blocks));

        $hints = self::hints($blocks);
        self::assertSame(self::nb('37 / 100') . ', every database of the server', $hints['Usage / Server connections']);
        self::assertSame('2 active · 4 idle · 1 idle in transaction', $hints['Usage / Database connections']);
        self::assertSame('at the next refresh', $hints['Traffic / Transactions per second']);
        self::assertSame('since the statistics were reset', $hints['Traffic / Rollbacks']);
        self::assertSame('at the next refresh', $hints['Traffic / Rows written per second']);
        self::assertSame('since the statistics were reset', $hints['Cache / Cache hit rate']);
        self::assertSame(self::nb('6 MB') . ' since the statistics were reset', $hints['Cache / Temporary files']);
        self::assertSame('the longest for ' . self::nb('45 s'), $hints['Sessions / Waiting for a lock']);
        self::assertSame('the oldest for ' . self::nb('5 min 40 s'), $hints['Sessions / Idle in a transaction']);
        self::assertSame('4,000 of 254,000 rows', $hints['Tables / Dead rows']);
        self::assertSame('every schema of shop', $hints['Tables / Database size']);
        self::assertSame('indexes included', $hints['Tables / Table size']);

        $blocked = $blocks[4]->content;
        self::assertInstanceOf(Table::class, $blocked);
        self::assertSame(['Session', 'Waiting for', 'Blocked by', 'State of the blocker'], array_map(static fn(Column $column): string => $column->label, $blocked->columns));

        $slowest = $blocks[7]->content;
        self::assertInstanceOf(Table::class, $slowest);
        self::assertSame('the slowest on average since the statistics were reset', $slowest->note);
        self::assertSame(['Query', 'Executions', 'Average', 'Max', 'Total'], array_map(static fn(Column $column): string => $column->label, $slowest->columns));
    }

    public function testTheRatesOfTheLastSamplesSayTheirWindow(): void
    {
        $previous = ['database_stats' => [
            'xact_commit' => 124_400,
            'xact_rollback' => 0,
            'blks_hit' => 336_000,
            'blks_read' => 3_880,
            'tup_inserted' => 4_400,
            'tup_updated' => 2_200,
            'tup_deleted' => 0,
        ]];
        $snapshot = self::snapshot(deltas: ['postgresql' => Snapshots::delta($previous, DatabasePayloads::postgresqlActivity())]);

        $blocks = (new PostgresqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW);
        $described = self::describe($blocks);

        self::assertSame(['Transactions per second' => self::nb('12.0 tx/s'), 'Rollbacks' => self::nb('16.6 %') . ' [Warning]', 'Rows written per second' => self::nb('20.0 rows/s')], $described[1][2]);
        self::assertSame(['Cache hit rate' => self::nb('99.8 %'), 'Disk reads per second' => self::nb('2.0 blocks/s'), 'Temporary files' => '3'], $described[2][2]);
        $hints = self::hints($blocks);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Traffic / Transactions per second']);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Traffic / Rollbacks']);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Traffic / Rows written per second']);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Cache / Cache hit rate']);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Cache / Disk reads per second']);
    }

    public function testADurationIsWrittenAsTheSentenceOfItsCheck(): void
    {
        $snapshot = self::snapshot();
        $findings = self::findings($snapshot);
        $sentences = array_column(array_map(static fn(Finding $finding): array => [$finding->id, $finding->sentence], $findings), 1, 0);

        $hints = self::hints((new PostgresqlDetail())->sections(self::connection($snapshot), $findings, Snapshots::NOW));

        self::assertStringEndsWith(substr($hints['Sessions / Idle in a transaction'], strlen('the oldest ')), $sentences['postgresql.idle_in_transaction']);
        self::assertStringEndsWith(substr($hints['Sessions / Waiting for a lock'], strlen('the longest ')), $sentences['postgresql.blocked_sessions']);
    }

    public function testAFactCarriesTheMarkOfItsCheck(): void
    {
        $activity = ['server_connections' => 96] + DatabasePayloads::postgresqlActivity();
        $database = DatabasePayloads::postgresqlDatabase();
        $database['tables'] = ['dead_tuples' => 30_000, 'live_tuples' => 70_000] + $database['tables'];
        $snapshot = self::snapshot([
            'postgresql' => $activity,
            'postgresql.database' => $database,
            'postgresql.settings' => ['autovacuum' => 'off', 'server_version' => '13.18'] + DatabasePayloads::postgresqlSettings(),
        ]);

        $described = self::describe((new PostgresqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW));

        self::assertSame(self::nb('96 %') . ' [Critical]', $described[0][2]['Server connections']);
        self::assertSame(self::nb('30 %') . ' [Warning]', $described[5][2]['Dead rows']);
        self::assertSame('off [Critical]', $described[6][2]['autovacuum']);
        self::assertSame('on', $described[6][2]['track_counts']);
        self::assertSame('PostgreSQL 13.18 [End of life]', $described[6][2]['Version']);
    }

    public function testTheBlockedSessionsTableHoldsNoQueryText(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['sessions']['blocked_queries'] = [[
            'blocked_pid' => 4242,
            'wait_seconds' => 45,
            'blocked_query_sample' => "UPDATE account SET password = 's3cret' WHERE id = 7",
            'blocking_pid' => 4100,
            'blocking_query_sample' => "UPDATE account SET email = 'jane@example.com' WHERE id = 7",
            'blocking_state' => 'idle in transaction',
        ]];
        $snapshot = self::snapshot(['postgresql' => $activity]);

        $blocks = (new PostgresqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW);
        $table = $blocks[4]->content;

        self::assertInstanceOf(Table::class, $table);
        self::assertSame(['pid', 'wait', 'blocking_pid', 'blocking_state'], array_map(static fn(Column $column): string => $column->id, $table->columns));
        $shown = serialize($blocks);
        self::assertStringNotContainsString('s3cret', $shown);
        self::assertStringNotContainsString('jane@example.com', $shown);
        self::assertStringNotContainsString('UPDATE', $shown);
    }

    public function testASessionWaitingForTwoOthersIsCountedOnce(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['sessions']['blocked_queries'][] = ['blocked_pid' => 4242, 'wait_seconds' => 45, 'blocking_pid' => 4101, 'blocking_state' => 'active'];
        $snapshot = self::snapshot(['postgresql' => $activity]);

        $described = self::describe((new PostgresqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW));

        self::assertSame('1 [Warning]', $described[3][2]['Waiting for a lock']);
        self::assertCount(2, $described[4][2], 'a row for each session it waits for');
    }

    public function testWithoutBlockedSessionTheTableSaysSo(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['sessions'] = ['blocked_count' => 0, 'blocked_queries' => []] + $activity['sessions'];
        $limited = $activity;
        $limited['hidden_sessions'] = 2;

        $empty = (new PostgresqlDetail())->sections(self::connection(self::snapshot(['postgresql' => $activity])), [], Snapshots::NOW)[4];
        $unseen = (new PostgresqlDetail())->sections(self::connection(self::snapshot(['postgresql' => $limited])), [], Snapshots::NOW)[5];

        self::assertSame([BlockState::Empty, Block::TABLE, 'No session waits for a lock'], [$empty->state, $empty->type, $empty->title]);
        self::assertSame([BlockState::Empty, Block::TABLE, 'No session this role can see waits for a lock'], [$unseen->state, $unseen->type, $unseen->title]);
    }

    public function testALimitedRoleIsToldAboveTheSessions(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['connections']['unknown'] = 4;
        $activity['hidden_sessions'] = 4;
        $snapshot = self::snapshot(['postgresql' => $activity]);

        $blocks = (new PostgresqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW);
        $titles = self::titles($blocks);

        self::assertSame(['Usage', 'Traffic', 'Cache', 'notice: This role cannot read the sessions of other roles', 'Sessions', 'Blocked sessions'], array_slice($titles, 0, 6));
        self::assertSame(
            ['notice', 'info', 'This role cannot read the sessions of other roles', 'PostgreSQL counts the 4 sessions of other roles in this database, but hides their state, their transaction and their waits from it: the oldest transaction, the sessions idle in a transaction and the waits below only cover the sessions it can read. Grant it the pg_monitor role to see them all.'],
            self::describe($blocks)[3],
        );
        self::assertSame(['Waiting for a lock' => '1', 'Oldest transaction' => self::nb('5 min 40 s'), 'Idle in a transaction' => '1'], self::describe($blocks)[4][2], 'the checks that depend on what it cannot see keep quiet');

        $activity['hidden_sessions'] = 1;
        $one = self::describe((new PostgresqlDetail())->sections(self::connection(self::snapshot(['postgresql' => $activity])), [], Snapshots::NOW))[3];

        self::assertStringStartsWith('PostgreSQL counts the 1 session of another role in this database, but hides its state, its transaction and its waits from it:', $one[3]);
        self::assertNotContains('notice: This role cannot read the sessions of other roles', self::titles((new PostgresqlDetail())->sections(self::connection(self::snapshot()), [], Snapshots::NOW)));
    }

    public function testWhatALimitedRoleReadsOnlyCoversTheSessionsItCanRead(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['connections']['unknown'] = 4;
        $activity['hidden_sessions'] = 4;
        $activity['sessions']['oldest_transaction_seconds'] = 0;

        $limited = self::hints((new PostgresqlDetail())->sections(self::connection(self::snapshot(['postgresql' => $activity])), [], Snapshots::NOW));
        $full = self::hints((new PostgresqlDetail())->sections(self::connection(self::snapshot()), [], Snapshots::NOW));

        self::assertSame('among the sessions this role can read', $limited['Sessions / Oldest transaction']);
        self::assertSame('the oldest for ' . self::nb('5 min 40 s') . ', among the sessions this role can read', $limited['Sessions / Idle in a transaction']);
        self::assertSame('the longest for ' . self::nb('45 s') . ', among the sessions this role can read', $limited['Sessions / Waiting for a lock']);
        self::assertSame('2 active · 4 idle · 1 idle in transaction · 4 unknown to this role', $limited['Usage / Database connections']);

        self::assertNull($full['Sessions / Oldest transaction']);
        self::assertSame('the oldest for ' . self::nb('5 min 40 s'), $full['Sessions / Idle in a transaction']);
        self::assertSame('the longest for ' . self::nb('45 s'), $full['Sessions / Waiting for a lock']);
        self::assertSame('2 active · 4 idle · 1 idle in transaction', $full['Usage / Database connections']);

        $activity['sessions']['oldest_idle_in_transaction_seconds'] = null;
        $activity['sessions']['idle_in_transaction_count'] = 0;
        $none = self::hints((new PostgresqlDetail())->sections(self::connection(self::snapshot(['postgresql' => $activity])), [], Snapshots::NOW));

        self::assertSame('among the sessions this role can read', $none['Sessions / Idle in a transaction']);
    }

    public function testDeadRowsOfAnEmptySchemaHaveNoCount(): void
    {
        $database = DatabasePayloads::postgresqlDatabase();
        $database['tables'] = ['dead_tuples' => 0, 'live_tuples' => 0] + $database['tables'];

        $blocks = (new PostgresqlDetail())->sections(self::connection(self::snapshot(['postgresql.database' => $database])), [], Snapshots::NOW);

        self::assertSame('—', self::describe($blocks)[5][2]['Dead rows']);
        self::assertNull(self::hints($blocks)['Tables / Dead rows']);
    }

    public function testWithoutTheExtensionTheSlowestQueriesSayWhy(): void
    {
        $reason = 'pg_stat_statements is not installed in this database: run CREATE EXTENSION pg_stat_statements.';
        $snapshot = self::snapshot(results: [new CollectorResult('postgresql.slow_queries', [], ResultState::Failed, $reason, expiresAt: Snapshots::NOW + 600, failure: FailureKind::MissingPrerequisite)]);

        $blocks = (new PostgresqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW);

        self::assertCount(8, $blocks);
        self::assertSame(['notice', 'warn', 'The slowest queries could not be read', $reason], self::describe($blocks)[7]);
    }

    public function testNoStatementIsNotAnError(): void
    {
        $snapshot = self::snapshot(['postgresql.slow_queries' => ['queries' => [], 'hidden' => 0]]);

        $last = (new PostgresqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW)[7];

        self::assertSame([BlockState::Empty, Block::TABLE, 'No statement of this database is in pg_stat_statements'], [$last->state, $last->type, $last->title]);
    }

    public function testStatementsHiddenFromTheRoleAreToldInsteadOfAnEmptyList(): void
    {
        $hidden = self::snapshot(['postgresql.slow_queries' => ['queries' => [], 'hidden' => 3]]);

        $last = (new PostgresqlDetail())->sections(self::connection($hidden), [], Snapshots::NOW)[7];

        self::assertSame([BlockState::Empty, Block::TABLE, 'This role cannot see the statements of the other roles'], [$last->state, $last->type, $last->title]);
        self::assertStringNotContainsString('pg_stat_statements', $last->title);
        self::assertStringContainsString('pg_read_all_stats', $last->text);
        self::assertStringContainsString('pg_monitor', $last->text);
        self::assertStringStartsWith('3 statements of other roles are hidden from this role', $last->text);

        $one = (new PostgresqlDetail())->sections(self::connection(self::snapshot(['postgresql.slow_queries' => ['queries' => [], 'hidden' => 1]])), [], Snapshots::NOW)[7];

        self::assertStringStartsWith('1 statement of another role is hidden from this role', $one->text);

        $some = self::snapshot(['postgresql.slow_queries' => ['hidden' => 2] + DatabasePayloads::postgresqlSlowQueries()]);
        $table = (new PostgresqlDetail())->sections(self::connection($some), [], Snapshots::NOW)[7]->content;

        self::assertInstanceOf(Table::class, $table);
        self::assertCount(1, $table->rows);
        self::assertSame('the slowest on average since the statistics were reset; this role cannot see 2 statements of other roles', $table->note);
    }

    public function testTheSlowSectionsWaitForTheirCollectors(): void
    {
        $snapshot = self::snapshot(states: ['postgresql.database' => ResultState::Pending, 'postgresql.slow_queries' => ResultState::Busy]);

        $blocks = (new PostgresqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW);

        self::assertSame([[BlockState::Reading->value, Block::FACTS], ['facts', 'Settings'], [BlockState::Reading->value, Block::TABLE]], array_map(
            static fn(array $line): array => array_slice($line, 0, 2),
            array_slice(self::describe($blocks), 5),
        ));
        self::assertSame(
            ['Usage', 'Traffic', 'Cache', 'Sessions', 'Blocked sessions', 'Settings'],
            self::titles((new PostgresqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW, false)),
        );
    }

    public function testAnUnreadableSectionLeavesTheRest(): void
    {
        $snapshot = self::snapshot(states: ['postgresql.settings' => ResultState::Failed, 'postgresql.database' => ResultState::Failed]);

        $titles = self::titles((new PostgresqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW));

        self::assertSame([
            'Usage', 'Traffic', 'Cache', 'Sessions', 'Blocked sessions',
            'notice: The tables of the database could not be read',
            'notice: The PostgreSQL settings could not be read',
            'Slowest queries',
        ], $titles);
    }

    public function testItsCountsAreWrittenLikeThoseOfATable(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['server_connections'] = 1_024;
        $activity['connections'] = ['active' => 1_200, 'unknown' => 1_500];
        $activity['hidden_sessions'] = 1_500;
        $activity['database_stats']['numbackends'] = 2_700;
        $activity['database_stats']['temp_files'] = 12_345;
        $database = DatabasePayloads::postgresqlDatabase();
        $database['tables']['dead_tuples'] = 1_793;
        $database['tables']['live_tuples'] = 219_636;
        $snapshot = self::snapshot([
            'postgresql' => $activity,
            'postgresql.settings' => ['max_connections' => '2000'] + DatabasePayloads::postgresqlSettings(),
            'postgresql.database' => $database,
            'postgresql.slow_queries' => ['hidden' => 1_200] + DatabasePayloads::postgresqlSlowQueries(),
        ]);

        $blocks = (new PostgresqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW);
        $hints = self::hints($blocks);
        $notice = $blocks[3]->content;
        $slowest = $blocks[8]->content;

        self::assertSame(self::nb('1,024 / 2,000') . ', every database of the server', $hints['Usage / Server connections']);
        self::assertSame('1,200 active · 1,500 unknown to this role', $hints['Usage / Database connections']);
        self::assertSame('2,700', self::describe($blocks)[0][2]['Database connections']);
        self::assertSame('12,345', self::describe($blocks)[2][2]['Temporary files']);
        self::assertSame('1,793 of 221,429 rows', $hints['Tables / Dead rows']);
        self::assertInstanceOf(Notice::class, $notice);
        self::assertStringStartsWith('PostgreSQL counts the 1,500 sessions of other roles in this database', $notice->text);
        self::assertInstanceOf(Table::class, $slowest);
        self::assertSame('the slowest on average since the statistics were reset; this role cannot see 1,200 statements of other roles', $slowest->note);
    }

    public function testARateWithoutSecondSampleIsOnlyPromisedWhileTheServerAnswers(): void
    {
        $dead = self::snapshot(results: [
            new CollectorResult('postgresql', DatabasePayloads::postgresqlActivity(), ResultState::Stale, 'Nothing answers on db1:5432.', Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ]);

        $hints = self::hints((new PostgresqlDetail())->sections(self::connection($dead), [], Snapshots::NOW));

        self::assertNull($hints['Traffic / Transactions per second']);
        self::assertNull($hints['Traffic / Rows written per second']);
        self::assertNull($hints['Cache / Disk reads per second']);
        self::assertSame('since the statistics were reset', $hints['Cache / Cache hit rate']);
    }

    public function testAnUnreachableServerIsToldOnce(): void
    {
        $unreachable = static fn(string $name): CollectorResult => new CollectorResult($name, [], ResultState::Failed, 'Nothing answers on db1:5432.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable);
        $stale = static fn(string $name, array $data): CollectorResult => new CollectorResult($name, $data, ResultState::Stale, 'Nothing answers on db1:5432.', Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable);
        $dead = new ComponentSnapshot(Component::Postgresql, [
            'postgresql' => $unreachable('postgresql'),
            'postgresql.settings' => $unreachable('postgresql.settings'),
            'postgresql.database' => $unreachable('postgresql.database'),
            'postgresql.slow_queries' => $unreachable('postgresql.slow_queries'),
        ]);
        $read = new ComponentSnapshot(Component::Postgresql, [
            'postgresql' => $stale('postgresql', DatabasePayloads::postgresqlActivity()),
            'postgresql.settings' => $stale('postgresql.settings', DatabasePayloads::postgresqlSettings()),
            'postgresql.database' => $stale('postgresql.database', DatabasePayloads::postgresqlDatabase()),
            'postgresql.slow_queries' => $unreachable('postgresql.slow_queries'),
        ]);

        self::assertSame([], (new PostgresqlDetail())->sections(self::connection($dead), [], Snapshots::NOW));

        $titles = self::titles((new PostgresqlDetail())->sections(self::connection($read), [], Snapshots::NOW));

        self::assertSame(['notice: Could not be read again: Nothing answers on db1:5432.', 'Usage', 'Traffic', 'Cache', 'Sessions', 'Blocked sessions', 'Tables', 'Settings'], $titles);
        self::assertSame([], (new PostgresqlDetail())->sections(new InstanceState('main', 'shop', 'Unknown Doctrine connection "main".', null), [], Snapshots::NOW));
    }

    public function testEverythingAServerSaysIsEscaped(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['sessions']['blocked_queries'][0]['blocking_state'] = '<i>idle</i>';
        $snapshot = self::snapshot([
            'postgresql' => $activity,
            'postgresql.database' => DatabasePayloads::postgresqlDatabase('<b>schema</b>'),
            'postgresql.settings' => ['TimeZone' => '<u>zone</u>'] + DatabasePayloads::postgresqlSettings(),
            'postgresql.slow_queries' => ['queries' => [['text' => 'SELECT \'<script>alert(1)</script>\'', 'executions' => 1, 'average_ms' => 1, 'max_ms' => 1, 'total_ms' => 1]], 'hidden' => 0],
        ]);

        $html = self::render((new PostgresqlDetail())->sections(new InstanceState('main', '"><img src=x>', null, $snapshot), [], Snapshots::NOW));

        foreach (['<script>', '<b>schema', '<u>zone', '<i>idle', '<img src=x>'] as $raw) {
            self::assertStringNotContainsString($raw, $html);
        }
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;schema&lt;/b&gt;', $html);
        self::assertStringContainsString('&lt;u&gt;zone&lt;/u&gt;', $html);
        self::assertStringContainsString('&lt;i&gt;idle&lt;/i&gt;', $html);
        self::assertStringContainsString('every schema of &quot;&gt;&lt;img src=x&gt;', $html);
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
     * @param array<string, array<string, mixed>> $data     replaces the payload of these collectors
     * @param array<string, ResultState>          $states
     * @param array<string, Delta>                $deltas
     * @param list<CollectorResult>               $results  replace the results of their collectors
     */
    private static function snapshot(array $data = [], array $states = [], array $deltas = [], array $results = []): ComponentSnapshot
    {
        $snapshot = Snapshots::of(Component::Postgresql, $data + [
            'postgresql' => DatabasePayloads::postgresqlActivity(),
            'postgresql.settings' => ['TimeZone' => 'Etc/UTC'] + DatabasePayloads::postgresqlSettings(),
            'postgresql.database' => DatabasePayloads::postgresqlDatabase(),
            'postgresql.slow_queries' => DatabasePayloads::postgresqlSlowQueries(),
            'eol.postgresql' => ['cycles' => [
                '17' => ['eol' => '2029-11-08', 'support' => '2029-11-08'],
                '13' => ['eol' => '2025-11-13', 'support' => '2025-11-13'],
            ]],
        ], $states, $deltas);

        foreach ($results as $result) {
            $snapshot = new ComponentSnapshot(Component::Postgresql, [...$snapshot->results, $result->name => $result]);
        }

        return $snapshot;
    }

    private static function connection(ComponentSnapshot $snapshot): InstanceState
    {
        return new InstanceState('main', 'shop', null, $snapshot);
    }
}
