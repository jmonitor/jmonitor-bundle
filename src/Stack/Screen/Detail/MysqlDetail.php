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
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\MysqlReading;
use Jmonitor\JmonitorBundle\Stack\Reading\MysqlSlowQuery;
use Jmonitor\JmonitorBundle\Stack\Screen\Marks;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class MysqlDetail implements DetailInterface
{
    private const SINCE_START = 'since the server started';

    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
    {
        $snapshot = Sections::snapshot($instance);

        if ($snapshot === null) {
            return [];
        }

        $mysql = MysqlReading::of($snapshot, new \DateTimeImmutable('@' . $now));
        $blocks = [
            ...Sections::of($snapshot, 'mysql', Block::GAUGES, $now, $reading, static fn(): array => self::status($mysql, $snapshot, $findings)),
            ...Sections::of($snapshot, 'mysql.variables', Block::FACTS, $now, $reading, static fn(): array => [self::settings($mysql)], 'The MySQL settings could not be read'),
        ];

        if ($instance->detail === null || $instance->detail === '') {
            return $blocks;
        }

        return [
            ...$blocks,
            ...Sections::of($snapshot, 'mysql.sizes', Block::FACTS, $now, $reading, static fn(): array => [self::database($mysql, $instance->detail)], 'The size of the database could not be read'),
            ...Sections::of($snapshot, 'mysql.slow_queries', Block::TABLE, $now, $reading, static fn(): array => [self::slowQueries($mysql)], 'The slowest queries could not be read'),
        ];
    }

    /**
     * The product and its number, such as "MariaDB 11.4.10".
     */
    public static function version(MysqlReading $mysql): ?string
    {
        return $mysql->product === null || $mysql->version === null ? null : $mysql->product . ' ' . $mysql->version;
    }

    public static function connections(MysqlReading $mysql): ?string
    {
        if ($mysql->connections === null) {
            return null;
        }

        return $mysql->maxConnections === null ? (string) $mysql->connections : Sections::fraction($mysql->connections, $mysql->maxConnections);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Block>
     */
    private static function status(MysqlReading $mysql, ComponentSnapshot $snapshot, array $findings): array
    {
        $elapsed = $snapshot->delta('mysql')?->elapsed;
        $limited = $mysql->maxConnections !== null;

        return [
            Block::gauges(new Gauges('Usage', [
                new Gauge(
                    'Connections',
                    $mysql->connectionsPercent,
                    $limited ? Sections::percent($mysql->connectionsPercent) : Sections::value(self::count($mysql->connections)),
                    Sections::mark($findings, 'mysql.connections'),
                    $limited ? self::connections($mysql) : 'connection limit unknown',
                ),
                new Gauge(
                    'Buffer pool',
                    $mysql->bufferPoolUsedPercent,
                    Sections::percent($mysql->bufferPoolUsedPercent),
                    hint: $mysql->bufferPoolSize === null ? null : 'of ' . Figures::bytes($mysql->bufferPoolSize),
                ),
            ])),
            Block::facts(new Facts('Traffic', [
                self::rate('Queries per second', Sections::rate($mysql->queriesPerSecond), $mysql->queriesPerSecond, $elapsed),
                new Fact('Running threads', Sections::value(self::count($mysql->running))),
                new Fact('Peak connections', Sections::value(self::count($mysql->maxUsedConnections)), hint: $mysql->maxUsedConnections === null ? null : self::SINCE_START),
                new Fact('Slow queries', Sections::value(self::count($mysql->slowQueriesSinceStart)), hint: $mysql->slowQueriesSinceStart === null ? null : self::slowQueriesHint($mysql)),
            ])),
            Block::facts(new Facts('InnoDB', [
                self::rate('Buffer pool hit rate', self::percent($mysql->bufferPoolHitRate), $mysql->bufferPoolHitRate, $elapsed, Sections::mark($findings, 'mysql.buffer_pool_hit_rate')),
                self::rate('Disk reads per second', Sections::rate($mysql->innodbReadsPerSecond), $mysql->innodbReadsPerSecond, $elapsed),
                self::rate('Disk writes per second', Sections::rate($mysql->innodbWritesPerSecond), $mysql->innodbWritesPerSecond, $elapsed),
            ])),
            Block::facts(new Facts('Temporary tables and locks', [
                self::rate('Temporary tables on disk', self::percent($mysql->diskTemporaryTables), $mysql->diskTemporaryTables, $elapsed, Sections::mark($findings, 'mysql.disk_temporary_tables')),
                self::rate('Table lock waits', self::percent($mysql->tableLockWaits), $mysql->tableLockWaits, $elapsed, Sections::mark($findings, 'mysql.table_lock_waits')),
            ])),
        ];
    }

    private static function settings(MysqlReading $mysql): Block
    {
        $setting = static fn(string $name): ?string => $mysql->settings[$name] ?? null;
        $waitTimeout = $setting('wait_timeout');
        $timeZone = $mysql->timeZone === 'SYSTEM' && $mysql->systemTimeZone !== null ? 'SYSTEM (' . $mysql->systemTimeZone . ')' : $mysql->timeZone;

        return Block::facts(new Facts('Settings', [
            new Fact('Version', Sections::value(self::version($mysql)), $mysql->support === null ? null : Marks::support($mysql->support), $mysql->versionComment),
            new Fact('max_connections', Sections::value(self::count($mysql->maxConnections))),
            new Fact('innodb_buffer_pool_size', $mysql->bufferPoolSize === null ? Sections::MISSING : Figures::bytes($mysql->bufferPoolSize)),
            new Fact('wait_timeout', $waitTimeout === null || !ctype_digit($waitTimeout) ? Sections::value($waitTimeout) : Sections::duration((int) $waitTimeout)),
            new Fact('slow_query_log', match ($mysql->slowQueryLog) {
                true => 'on',
                false => 'off',
                null => Sections::MISSING,
            }),
            new Fact('long_query_time', $mysql->longQueryTime === null ? Sections::MISSING : self::seconds($mysql->longQueryTime)),
            new Fact('Character set', Sections::value($setting('character_set_server')), hint: $setting('collation_server')),
            new Fact('Time zone', Sections::value($timeZone)),
            new Fact('Uptime', $mysql->uptime === null || $mysql->uptime < 0 ? Sections::MISSING : Sections::duration($mysql->uptime)),
        ]));
    }

    private static function database(MysqlReading $mysql, string $database): Block
    {
        $total = $mysql->dataLength === null || $mysql->indexLength === null ? null : $mysql->dataLength + $mysql->indexLength;

        return Block::facts(new Facts('Database · ' . $database, [
            new Fact('Data', $mysql->dataLength === null ? Sections::MISSING : Figures::bytes($mysql->dataLength)),
            new Fact('Indexes', $mysql->indexLength === null ? Sections::MISSING : Figures::bytes($mysql->indexLength)),
            new Fact('Total', $total === null ? Sections::MISSING : Figures::bytes($total)),
        ], $mysql->sizesCached ? 'As cached by MySQL, up to a day old' : null));
    }

    private static function slowQueries(MysqlReading $mysql): Block
    {
        if ($mysql->slowQueries === []) {
            return Block::unavailable(Block::TABLE, BlockState::Empty, 'No query of this database is in performance_schema', 'The statements the application runs on it show here, the slowest on average first.');
        }

        $duration = static fn(?int $ms): Cell => new Cell($ms === null ? Sections::MISSING : Sections::unbroken(Format::duration($ms)));

        return Block::table(new Table(
            'mysql-slow-queries',
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
            ]), $mysql->slowQueries),
            noun: 'query',
            pluralNoun: 'queries',
            note: self::SINCE_START,
        ));
    }

    private static function rate(string $label, ?string $value, Rate $rate, ?int $elapsed, ?Mark $mark = null): Fact
    {
        return new Fact($label, Sections::value($value), $mark, Figures::span($rate, $elapsed));
    }

    private static function percent(Rate $rate): ?string
    {
        return $rate->value === null ? null : Figures::percent($rate->value);
    }

    private static function slowQueriesHint(MysqlReading $mysql): string
    {
        return $mysql->longQueryTime === null ? self::SINCE_START : 'longer than ' . self::seconds($mysql->longQueryTime) . ', ' . self::SINCE_START;
    }

    private static function seconds(float $seconds): string
    {
        return Sections::unbroken(Sections::decimal($seconds, 6) . ' s');
    }

    private static function count(?int $count): ?string
    {
        return $count === null ? null : (string) $count;
    }
}
