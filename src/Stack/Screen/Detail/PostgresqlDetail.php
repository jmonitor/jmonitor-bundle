<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\Ratios;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\MysqlSlowQuery;
use Jmonitor\JmonitorBundle\Stack\Reading\PostgresqlBlockedSession;
use Jmonitor\JmonitorBundle\Stack\Reading\PostgresqlReading;
use Jmonitor\JmonitorBundle\Stack\Reading\Values;
use Jmonitor\JmonitorBundle\Stack\Screen\Marks;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class PostgresqlDetail implements DetailInterface
{
    private const SINCE_RESET = 'since the statistics were reset';

    private const WRITES = ['tup_inserted', 'tup_updated', 'tup_deleted'];

    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
    {
        $snapshot = Sections::snapshot($instance);

        if ($snapshot === null) {
            return [];
        }

        $postgresql = PostgresqlReading::of($snapshot, new \DateTimeImmutable('@' . $now));

        return [
            ...Sections::of($snapshot, 'postgresql', Block::GAUGES, $now, $reading, static fn(): array => self::activity($postgresql, $snapshot, $findings)),
            ...Sections::of($snapshot, 'postgresql.database', Block::FACTS, $now, $reading, static fn(): array => [self::tables($postgresql, $instance->detail, $findings)], 'The tables of the database could not be read'),
            ...Sections::of($snapshot, 'postgresql.settings', Block::FACTS, $now, $reading, static fn(): array => [self::settings($postgresql, $findings)], 'The PostgreSQL settings could not be read'),
            ...Sections::of($snapshot, 'postgresql.slow_queries', Block::TABLE, $now, $reading, static fn(): array => [self::slowQueries($postgresql)], 'The slowest queries could not be read'),
        ];
    }

    /**
     * The product and its number, such as "PostgreSQL 17.2".
     */
    public static function version(PostgresqlReading $postgresql): ?string
    {
        return $postgresql->version === null ? null : 'PostgreSQL ' . $postgresql->version;
    }

    /**
     * Those of the whole server.
     */
    public static function connections(PostgresqlReading $postgresql): ?string
    {
        if ($postgresql->serverConnections === null) {
            return null;
        }

        return $postgresql->maxConnections === null ? (string) $postgresql->serverConnections : Sections::fraction($postgresql->serverConnections, $postgresql->maxConnections);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Block>
     */
    private static function activity(PostgresqlReading $postgresql, ComponentSnapshot $snapshot, array $findings): array
    {
        $elapsed = $snapshot->delta('postgresql')?->elapsed;
        $limited = $postgresql->maxConnections !== null;
        $stats = self::stats($snapshot);
        $temporaryBytes = Values::int($stats['temp_bytes'] ?? null);
        $diskReads = Ratios::perSecond($snapshot->delta('postgresql'), 'database_stats.blks_read', 'blocks/s', Values::int($stats['blks_read'] ?? null));
        $rowsWritten = self::rowsWritten($snapshot, $stats);

        return [
            Block::gauges(new Gauges('Usage', [
                new Gauge(
                    'Server connections',
                    $postgresql->connectionsPercent,
                    $limited ? Sections::percent($postgresql->connectionsPercent) : Sections::value(self::count($postgresql->serverConnections)),
                    Sections::mark($findings, 'postgresql.connections'),
                    $limited ? self::connections($postgresql) . ', every database of the server' : 'connection limit unknown',
                ),
                new Gauge('Database connections', null, Sections::value(self::count($postgresql->databaseConnections)), hint: self::states($postgresql)),
            ])),
            Block::facts(new Facts('Traffic', [
                self::rate('Transactions per second', Sections::rate($postgresql->transactionsPerSecond), $postgresql->transactionsPerSecond, $elapsed),
                self::rate('Rollbacks', self::percent($postgresql->rollbackRate), $postgresql->rollbackRate, $elapsed, Sections::mark($findings, 'postgresql.rollbacks')),
                self::rate('Rows written per second', Sections::rate($rowsWritten), $rowsWritten, $elapsed),
            ])),
            Block::facts(new Facts('Cache', [
                self::rate('Cache hit rate', self::percent($postgresql->cacheHitRate), $postgresql->cacheHitRate, $elapsed, Sections::mark($findings, 'postgresql.cache_hit_rate')),
                self::rate('Disk reads per second', Sections::rate($diskReads), $diskReads, $elapsed),
                new Fact('Temporary files', Sections::value(self::count(Values::int($stats['temp_files'] ?? null))), hint: $temporaryBytes === null ? null : Figures::bytes($temporaryBytes) . ' ' . self::SINCE_RESET),
            ])),
            ...self::sessions($postgresql, $findings),
        ];
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Block>
     */
    private static function sessions(PostgresqlReading $postgresql, array $findings): array
    {
        $blocks = [];

        if ($postgresql->limitedRole) {
            $hidden = (int) $postgresql->hiddenSessions;
            $blocks[] = Block::notice(new Notice('info', 'This role only sees its own sessions', sprintf(
                'PostgreSQL does not show it the state of %d %s of other roles in this database, so the counts below leave them out. Grant it the pg_monitor role to see them all.',
                $hidden,
                $hidden === 1 ? 'session' : 'sessions',
            )));
        }

        $blocks[] = Block::facts(new Facts('Sessions', [
            new Fact('Waiting for a lock', Sections::value(self::count($postgresql->blockedSessions)), Sections::mark($findings, 'postgresql.blocked_sessions'), $postgresql->longestBlock === null ? null : 'the longest for ' . Figures::duration($postgresql->longestBlock)),
            new Fact('Oldest transaction', $postgresql->oldestTransaction === null ? Sections::MISSING : Figures::duration($postgresql->oldestTransaction)),
            new Fact('Idle in a transaction', Sections::value(self::count($postgresql->idleInTransaction)), Sections::mark($findings, 'postgresql.idle_in_transaction'), $postgresql->oldestIdleInTransaction === null ? null : 'the oldest for ' . Figures::duration($postgresql->oldestIdleInTransaction)),
        ]));

        if ($postgresql->blocked === []) {
            $blocks[] = Block::unavailable(
                Block::TABLE,
                BlockState::Empty,
                $postgresql->limitedRole ? 'No session this role can see waits for a lock' : 'No session waits for a lock',
                'The sessions waiting for another one to release a lock show here, with the session that holds it.',
            );

            return $blocks;
        }

        $blocks[] = Block::table(new Table(
            'postgresql-blocked-sessions',
            'Blocked sessions',
            [
                new Column('pid', 'Session'),
                new Column('wait', 'Waiting for'),
                new Column('blocking_pid', 'Blocked by'),
                new Column('blocking_state', 'State of the blocker', false),
            ],
            array_map(static fn(PostgresqlBlockedSession $session): Row => new Row((string) $session->pid, [
                'pid' => new Cell((string) $session->pid),
                'wait' => new Cell($session->waitSeconds === null ? Sections::MISSING : Figures::duration($session->waitSeconds)),
                'blocking_pid' => new Cell($session->blockingPid === null ? Sections::MISSING : (string) $session->blockingPid),
                'blocking_state' => new Cell(Sections::value($session->blockingState)),
            ]), $postgresql->blocked),
            noun: 'session',
        ));

        return $blocks;
    }

    /**
     * @param list<Finding> $findings
     */
    private static function tables(PostgresqlReading $postgresql, ?string $database, array $findings): Block
    {
        $bytes = static fn(?int $bytes): string => $bytes === null ? Sections::MISSING : Figures::bytes($bytes);

        return Block::facts(new Facts('Tables', [
            new Fact('Schema', Sections::value($postgresql->schema)),
            new Fact('Tables', Sections::value(self::count($postgresql->tables))),
            new Fact(
                'Dead rows',
                $postgresql->deadRowsPercent === null ? Sections::MISSING : Figures::percent($postgresql->deadRowsPercent),
                Sections::mark($findings, 'postgresql.dead_rows'),
                $postgresql->deadRows === null || $postgresql->liveRows === null ? null : sprintf('%d of %d rows', $postgresql->deadRows, $postgresql->deadRows + $postgresql->liveRows),
            ),
            new Fact('Database size', $bytes($postgresql->databaseSize), hint: $database === null || $database === '' ? 'every schema included' : 'every schema of ' . $database),
            new Fact('Table size', $bytes($postgresql->tablesSize), hint: 'indexes included'),
            new Fact('Index size', $bytes($postgresql->indexesSize)),
        ]));
    }

    /**
     * @param list<Finding> $findings
     */
    private static function settings(PostgresqlReading $postgresql, array $findings): Block
    {
        $bytes = static fn(string $name): string => is_int($postgresql->settings[$name] ?? null) ? Figures::bytes($postgresql->settings[$name]) : Sections::value(self::setting($postgresql, $name));
        $switch = static fn(?bool $on): string => match ($on) {
            true => 'on',
            false => 'off',
            null => Sections::MISSING,
        };
        $off = Sections::mark($findings, 'postgresql.autovacuum_off');

        return Block::facts(new Facts('Settings', [
            new Fact('Version', Sections::value(self::version($postgresql)), $postgresql->support === null ? null : Marks::support($postgresql->support)),
            new Fact('max_connections', Sections::value(self::count($postgresql->maxConnections))),
            new Fact('shared_buffers', $bytes('shared_buffers')),
            new Fact('work_mem', $bytes('work_mem')),
            new Fact('autovacuum', $switch($postgresql->autovacuum), $postgresql->autovacuum === false ? $off : null),
            new Fact('track_counts', $switch($postgresql->trackCounts), $postgresql->trackCounts === false ? $off : null),
            new Fact('Time zone', Sections::value(self::setting($postgresql, 'TimeZone'))),
        ]));
    }

    private static function slowQueries(PostgresqlReading $postgresql): Block
    {
        $hidden = (int) $postgresql->hiddenStatements;

        if ($postgresql->slowQueries === [] && $hidden > 0) {
            return Block::unavailable(Block::TABLE, BlockState::Empty, 'This role cannot see the statements of the other roles', sprintf(
                '%s, and pg_stat_statements only shows a role the text of its own. Grant it pg_read_all_stats or pg_monitor to see them.',
                $hidden === 1 ? '1 of the slowest statements belongs to another role' : $hidden . ' of the slowest statements belong to other roles',
            ));
        }

        if ($postgresql->slowQueries === []) {
            return Block::unavailable(Block::TABLE, BlockState::Empty, 'No statement of this database is in pg_stat_statements', 'The statements the application runs on it show here, the slowest on average first.');
        }

        $duration = static fn(?int $ms): Cell => new Cell($ms === null ? Sections::MISSING : Sections::unbroken(Format::duration($ms)));

        return Block::table(new Table(
            'postgresql-slow-queries',
            'Slowest queries',
            [
                new Column('query', 'Query', false),
                new Column('executions', 'Executions'),
                new Column('average', 'Average'),
                new Column('max', 'Max'),
                new Column('total', 'Total'),
            ],
            array_map(static fn(MysqlSlowQuery $query): Row => new Row(hash('xxh128', $query->text), [
                'query' => new Cell($query->text, kind: 'sql'),
                'executions' => new Cell(Format::number($query->executions)),
                'average' => $duration($query->averageMs),
                'max' => $duration($query->maxMs),
                'total' => $duration($query->totalMs),
            ]), $postgresql->slowQueries),
            noun: 'query',
            pluralNoun: 'queries',
            note: 'the slowest on average ' . self::SINCE_RESET . match ($hidden) {
                0 => '',
                1 => '; this role cannot see 1 statement of another role',
                default => '; this role cannot see ' . $hidden . ' statements of other roles',
            },
        ));
    }

    /**
     * "2 active · 4 idle".
     */
    private static function states(PostgresqlReading $postgresql): ?string
    {
        $states = array_map(static fn(string $state, int $count): string => $count . ' ' . $state, array_keys($postgresql->connectionsByState), $postgresql->connectionsByState);

        return $states === [] ? null : implode(' · ', $states);
    }

    /**
     * @param array<mixed> $stats
     */
    private static function rowsWritten(ComponentSnapshot $snapshot, array $stats): Rate
    {
        $delta = $snapshot->delta('postgresql');

        if ($delta === null) {
            return Values::int($stats['tup_inserted'] ?? null) === null ? Rate::unavailable('rows/s') : Rate::pending('rows/s');
        }

        $written = 0;

        foreach (self::WRITES as $key) {
            $rows = $delta->getValue('database_stats.' . $key);

            if ($rows === null) {
                return Rate::unavailable('rows/s');
            }

            $written += $rows;
        }

        return new Rate(round($written / $delta->elapsed, 2), RateSource::Delta, 'rows/s');
    }

    /**
     * @return array<mixed>
     */
    private static function stats(ComponentSnapshot $snapshot): array
    {
        $stats = $snapshot->data('postgresql')['database_stats'] ?? null;

        return is_array($stats) ? $stats : [];
    }

    private static function setting(PostgresqlReading $postgresql, string $name): ?string
    {
        $value = $postgresql->settings[$name] ?? null;

        return $value === null ? null : (string) $value;
    }

    private static function rate(string $label, ?string $value, Rate $rate, ?int $elapsed, ?Mark $mark = null): Fact
    {
        return new Fact($label, Sections::value($value), $mark, Figures::span($rate, $elapsed));
    }

    private static function percent(Rate $rate): ?string
    {
        return $rate->value === null ? null : Figures::percent($rate->value);
    }

    private static function count(?int $count): ?string
    {
        return $count === null ? null : (string) $count;
    }
}
