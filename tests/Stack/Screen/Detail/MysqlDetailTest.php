<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\MysqlDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;

final class MysqlDetailTest extends TestCase
{
    use DescribesSections;

    public function testAServerShowsItsUsageTrafficEngineSettingsDatabaseAndSlowestQueries(): void
    {
        $snapshot = self::snapshot();

        $blocks = (new MysqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['gauges', 'Usage', ['Connections' => self::nb('30 %'), 'Buffer pool' => self::nb('87.5 %')]],
            ['facts', 'Traffic', [
                'Queries per second' => '—',
                'Running threads' => '3',
                'Peak connections' => '45',
                'Slow queries' => '12',
            ]],
            ['facts', 'InnoDB', [
                'Buffer pool hit rate' => self::nb('99 %'),
                'Disk reads per second' => '—',
                'Disk writes per second' => '—',
            ]],
            ['facts', 'Temporary tables and locks', [
                'Temporary tables on disk' => self::nb('13.3 %'),
                'Table lock waits' => self::nb('0.2 %'),
            ]],
            ['facts', 'Settings', [
                'Version' => 'MySQL 8.4.0',
                'max_connections' => '100',
                'innodb_buffer_pool_size' => self::nb('128 MB'),
                'wait_timeout' => self::nb('8 h'),
                'slow_query_log' => 'on',
                'long_query_time' => self::nb('1 s'),
                'Character set' => 'utf8mb4',
                'Time zone' => 'SYSTEM (UTC)',
                'Uptime' => self::nb('1 d'),
            ]],
            ['facts', 'Database · shop', ['Data' => self::nb('50 MB'), 'Indexes' => self::nb('10 MB'), 'Total' => self::nb('60 MB')]],
            ['table', 'Slowest queries', ['SELECT * FROM `order` WHERE `status` = ? | 120 | ' . self::nb('300 ms') . ' | ' . self::nb('1.2 s') . ' | ' . self::nb('36 s')]],
        ], self::describe($blocks));

        $hints = self::hints($blocks);
        self::assertSame(self::nb('30 / 100'), $hints['Usage / Connections']);
        self::assertSame('of ' . self::nb('128 MB'), $hints['Usage / Buffer pool']);
        self::assertSame('at the next refresh', $hints['Traffic / Queries per second']);
        self::assertSame('since the server started', $hints['Traffic / Peak connections']);
        self::assertSame('longer than ' . self::nb('1 s') . ', since the server started', $hints['Traffic / Slow queries']);
        self::assertSame('since the server started', $hints['InnoDB / Buffer pool hit rate']);
        self::assertSame('since the server started', $hints['Temporary tables and locks / Temporary tables on disk']);
        self::assertSame('MySQL Community Server - GPL', $hints['Settings / Version']);
        self::assertSame('utf8mb4_0900_ai_ci', $hints['Settings / Character set']);

        $table = $blocks[6]->content;
        self::assertInstanceOf(Table::class, $table);
        self::assertSame('the slowest on average since the server started', $table->note);
        self::assertSame(['query', 'executions', 'average', 'max', 'total'], array_map(static fn(Column $column): string => $column->id, $table->columns));
        self::assertSame(['Query', 'Executions', 'Average', 'Max', 'Total'], array_map(static fn(Column $column): string => $column->label, $table->columns));
        self::assertEquals(new Cell('SELECT * FROM `order` WHERE `status` = ?', kind: 'sql'), $table->rows[0]->cells['query']);
    }

    public function testTheRatesOfTheLastSamplesSayTheirWindow(): void
    {
        $previous = ['Questions' => '550', 'Innodb_buffer_pool_read_requests' => '100000', 'Innodb_buffer_pool_reads' => '1000', 'Innodb_data_reads' => '430', 'Innodb_data_writes' => '160'];
        $snapshot = self::snapshot(deltas: ['mysql' => Snapshots::delta($previous, DatabasePayloads::mysqlStatus())]);

        $blocks = (new MysqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(['Queries per second' => self::nb('10.0 q/s'), 'Running threads' => '3', 'Peak connections' => '45', 'Slow queries' => '12'], self::describe($blocks)[1][2]);
        self::assertSame(['Buffer pool hit rate' => self::nb('99 %'), 'Disk reads per second' => self::nb('2.0 reads/s'), 'Disk writes per second' => self::nb('1.0 writes/s')], self::describe($blocks)[2][2]);
        $hints = self::hints($blocks);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['Traffic / Queries per second']);
        self::assertSame('over the last ' . self::nb('60 s'), $hints['InnoDB / Buffer pool hit rate']);
    }

    public function testTheDatabaseSectionsWaitForTheirSlowCollectors(): void
    {
        $snapshot = self::snapshot(states: ['mysql.sizes' => ResultState::Pending, 'mysql.slow_queries' => ResultState::Busy]);

        $blocks = (new MysqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW);

        self::assertCount(7, $blocks);
        self::assertSame([[BlockState::Reading->value, Block::FACTS], [BlockState::Reading->value, Block::TABLE]], array_slice(self::describe($blocks), -2));
        self::assertSame(
            ['Usage', 'Traffic', 'InnoDB', 'Temporary tables and locks', 'Settings'],
            self::titles((new MysqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW, false)),
        );
    }

    public function testWithoutPerformanceSchemaTheTableSaysWhy(): void
    {
        $reason = 'performance_schema is off on this server, so its slowest queries cannot be read.';
        $snapshot = self::snapshot(results: [new CollectorResult('mysql.slow_queries', [], ResultState::Failed, $reason, expiresAt: Snapshots::NOW + 600, failure: FailureKind::MissingPrerequisite)]);

        $blocks = (new MysqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(['notice', 'warn', 'The slowest queries could not be read', $reason], self::describe($blocks)[6]);
        self::assertCount(7, $blocks);
    }

    public function testNoSlowQueryIsNotAnError(): void
    {
        $snapshot = self::snapshot(['mysql.slow_queries' => ['queries' => []]]);

        $blocks = (new MysqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW);
        $last = $blocks[6];

        self::assertSame(BlockState::Empty, $last->state);
        self::assertSame(Block::TABLE, $last->type);
        self::assertSame('No query of this database is in performance_schema', $last->title);
        self::assertCount(7, $blocks);
    }

    public function testTheCachedSizesSaySo(): void
    {
        $mysql = (new MysqlDetail())->sections(self::connection(self::snapshot()), [], Snapshots::NOW)[5]->content;

        self::assertInstanceOf(Facts::class, $mysql);
        self::assertSame('As cached by MySQL, up to a day old', $mysql->note);

        $variables = ['version' => '11.4.10-MariaDB-ubu2404', 'version_comment' => 'mariadb.org binary distribution'] + DatabasePayloads::mysqlVariables();
        $mariadb = (new MysqlDetail())->sections(self::connection(self::snapshot(['mysql.variables' => $variables])), [], Snapshots::NOW)[5]->content;

        self::assertInstanceOf(Facts::class, $mariadb);
        self::assertSame('Database · shop', $mariadb->title);
        self::assertNull($mariadb->note);
    }

    public function testAFactCarriesTheMarkOfItsCheck(): void
    {
        $status = ['Threads_connected' => '96', 'Innodb_buffer_pool_reads' => '22000', 'Created_tmp_disk_tables' => '60'] + DatabasePayloads::mysqlStatus();
        $snapshot = self::snapshot(['mysql' => $status]);

        $blocks = (new MysqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW);
        $described = self::describe($blocks);

        self::assertSame(self::nb('96 %') . ' [Critical]', $described[0][2]['Connections']);
        self::assertSame(self::nb('80 %') . ' [Warning]', $described[2][2]['Buffer pool hit rate']);
        self::assertSame(self::nb('40 %') . ' [Warning]', $described[3][2]['Temporary tables on disk']);
        self::assertSame(self::nb('0.2 %'), $described[3][2]['Table lock waits']);
    }

    public function testAnEndedVersionCarriesItsMark(): void
    {
        $variables = ['version' => '8.1.0'] + DatabasePayloads::mysqlVariables();
        $snapshot = self::snapshot(['mysql.variables' => $variables]);

        $blocks = (new MysqlDetail())->sections(self::connection($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame('MySQL 8.1.0 [End of life]', self::describe($blocks)[4][2]['Version']);
    }

    public function testAConnectionWithoutDatabaseHasNoDatabaseSection(): void
    {
        $snapshot = Snapshots::of(Component::Mysql, ['mysql' => DatabasePayloads::mysqlStatus(), 'mysql.variables' => DatabasePayloads::mysqlVariables()]);

        $blocks = (new MysqlDetail())->sections(new InstanceState('main', null, null, $snapshot), [], Snapshots::NOW);

        self::assertSame(['Usage', 'Traffic', 'InnoDB', 'Temporary tables and locks', 'Settings'], self::titles($blocks));
    }

    public function testAnUnreadableSettingOrServerLeavesTheRest(): void
    {
        $snapshot = self::snapshot(states: ['mysql.variables' => ResultState::Failed]);

        $blocks = (new MysqlDetail())->sections(self::connection($snapshot), [], Snapshots::NOW);

        self::assertSame(['Usage', 'Traffic', 'InnoDB', 'Temporary tables and locks', 'notice: The MySQL settings could not be read', 'Database · shop', 'Slowest queries'], self::titles($blocks));

        $unreachable = new InstanceState('main', 'shop', 'Unknown Doctrine connection "main".', null);

        self::assertSame([], (new MysqlDetail())->sections($unreachable, [], Snapshots::NOW));
    }

    public function testADeadServerShowsNoSectionOfItsOwnFailure(): void
    {
        $unreachable = static fn(string $name): CollectorResult => new CollectorResult($name, [], ResultState::Failed, 'Nothing answers.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable);
        $dead = new ComponentSnapshot(Component::Mysql, [
            'mysql' => $unreachable('mysql'),
            'mysql.variables' => $unreachable('mysql.variables'),
            'mysql.sizes' => $unreachable('mysql.sizes'),
            'mysql.slow_queries' => $unreachable('mysql.slow_queries'),
        ]);
        $denied = new ComponentSnapshot(Component::Mysql, [
            ...$dead->results,
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', [], ResultState::Failed, 'Access denied.', expiresAt: Snapshots::NOW + 3_600, failure: FailureKind::MissingPrerequisite),
        ]);
        $answering = self::snapshot(results: [$unreachable('mysql.sizes')]);

        self::assertSame([], (new MysqlDetail())->sections(self::connection($dead), [], Snapshots::NOW));
        self::assertSame([['notice', 'warn', 'The slowest queries could not be read', 'Access denied.']], self::describe((new MysqlDetail())->sections(self::connection($denied), [], Snapshots::NOW)));
        self::assertSame(['notice', 'warn', 'The size of the database could not be read', 'Nothing answers.'], self::describe((new MysqlDetail())->sections(self::connection($answering), [], Snapshots::NOW))[5], 'the server answers: only the sizes cannot be read');
    }

    public function testADeadServerSaysOnceThatItsFiguresAreOld(): void
    {
        $stale = static fn(string $name, array $data): CollectorResult => new CollectorResult($name, $data, ResultState::Stale, 'Nothing answers.', Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable);
        $unreachable = static fn(string $name): CollectorResult => new CollectorResult($name, [], ResultState::Failed, 'Nothing answers.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable);
        $dead = new ComponentSnapshot(Component::Mysql, [
            'mysql' => $stale('mysql', DatabasePayloads::mysqlStatus()),
            'mysql.variables' => $stale('mysql.variables', DatabasePayloads::mysqlVariables()),
            'mysql.sizes' => $unreachable('mysql.sizes'),
            'mysql.slow_queries' => $unreachable('mysql.slow_queries'),
        ]);

        $blocks = (new MysqlDetail())->sections(self::connection($dead), [], Snapshots::NOW);

        self::assertSame(['notice: Could not be read again: Nothing answers.', 'Usage', 'Traffic', 'InnoDB', 'Temporary tables and locks', 'Settings'], self::titles($blocks));
    }

    public function testAStaleSettingThatFailedOtherwiseKeepsItsNotice(): void
    {
        $other = new CollectorResult('mysql.variables', DatabasePayloads::mysqlVariables(), ResultState::Stale, 'Access denied.', Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Other);
        $dead = self::snapshot(results: [
            new CollectorResult('mysql', DatabasePayloads::mysqlStatus(), ResultState::Stale, 'Nothing answers.', Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
            $other,
        ]);

        $titles = self::titles((new MysqlDetail())->sections(self::connection($dead), [], Snapshots::NOW));

        self::assertSame(['notice: Could not be read again: Nothing answers.', 'Usage', 'Traffic', 'InnoDB', 'Temporary tables and locks', 'notice: Could not be read again: Access denied.', 'Settings'], array_slice($titles, 0, 7));
    }

    public function testWhatIsShownIsEscapedByTheTemplatesNotHere(): void
    {
        $queries = ['queries' => [['text' => '<script>alert(1)</script>', 'executions' => 1, 'average_ms' => 1, 'max_ms' => 1, 'total_ms' => 1]]];
        $snapshot = self::snapshot(['mysql.slow_queries' => $queries]);

        $blocks = (new MysqlDetail())->sections(new InstanceState('main', '"><b>', null, $snapshot), [], Snapshots::NOW);

        self::assertSame('Database · "><b>', self::titles($blocks)[5]);
        $table = $blocks[6]->content;
        self::assertInstanceOf(Table::class, $table);
        self::assertSame('<script>alert(1)</script>', $table->rows[0]->cells['query']->text);
    }

    /**
     * @param array<string, array<string, mixed>> $data     replaces the payload of these collectors
     * @param array<string, ResultState>          $states
     * @param array<string, Delta>                $deltas
     * @param list<CollectorResult>               $results  replace the results of their collectors
     */
    private static function snapshot(array $data = [], array $states = [], array $deltas = [], array $results = []): ComponentSnapshot
    {
        $snapshot = Snapshots::of(Component::Mysql, $data + [
            'mysql' => DatabasePayloads::mysqlStatus(),
            'mysql.variables' => DatabasePayloads::mysqlVariables(),
            'mysql.sizes' => DatabasePayloads::mysqlSizes(),
            'mysql.slow_queries' => DatabasePayloads::mysqlSlowQueries(),
            'eol.mysql' => Snapshots::cycles(),
        ], $states, $deltas);

        foreach ($results as $result) {
            $snapshot = new ComponentSnapshot(Component::Mysql, [...$snapshot->results, $result->name => $result]);
        }

        return $snapshot;
    }

    private static function connection(ComponentSnapshot $snapshot): InstanceState
    {
        return new InstanceState(null, 'shop', null, $snapshot);
    }
}
