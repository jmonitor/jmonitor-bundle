<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Reading\MysqlReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class MysqlChecks
{
    /** fewer locks between two samples tell nothing about their ratio */
    private const TABLE_LOCKS = 100;

    /** a server that just started has not filled its buffer pool nor met its usual queries yet */
    private const UPTIME = 3_600;

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    public static function of(MysqlReading $mysql, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        $findings = [];
        $product = $mysql->product ?? 'MySQL';
        $elapsed = $snapshot->delta('mysql')?->elapsed;

        if ($mysql->connectionsPercent !== null && $mysql->connectionsPercent >= 80) {
            $findings[] = $raise(
                'mysql.connections',
                $mysql->connectionsPercent >= 95 ? Severity::Critical : Severity::Warning,
                sprintf('%s holds %s of its connections (%d of %d)', $product, Figures::percent($mysql->connectionsPercent), $mysql->connections, $mysql->maxConnections),
                'Past max_connections, the server refuses new connections, and the requests that need the database fail. Find what holds the connections, such as long queries or processes that keep theirs open, or raise max_connections if the memory of the server allows it.',
                'mysql',
                'mysql.variables',
            );
        }

        $hitRate = $mysql->bufferPoolHitRate;

        if (self::judged($hitRate, $mysql->uptime) && $hitRate->value !== null && $hitRate->value < 95) {
            $findings[] = $raise(
                'mysql.buffer_pool_hit_rate',
                Severity::Warning,
                sprintf('The buffer pool hit rate is %s %s', Figures::percent($hitRate->value), Figures::span($hitRate, $elapsed)),
                'Every miss reads a page from the disk instead of the memory, and the queries wait for it. Raise innodb_buffer_pool_size, as far as the memory of the server allows it, so that the data the application uses fits in it.',
                'mysql',
            );
        }

        $temporary = $mysql->diskTemporaryTables;

        if (self::judged($temporary, $mysql->uptime) && $temporary->value !== null && $temporary->value >= 25) {
            $findings[] = $raise(
                'mysql.disk_temporary_tables',
                Severity::Warning,
                sprintf('%s of the temporary tables were written to disk %s', Figures::percent($temporary->value), Figures::span($temporary, $elapsed)),
                'A query that sorts or groups a large result builds a temporary table, written to disk when it outgrows the memory allowed or holds TEXT or BLOB columns, which slows it down. Raise tmp_table_size and max_heap_table_size together, or rework the queries that build the largest ones.',
                'mysql',
            );
        }

        $locks = $mysql->tableLockWaits;

        if ($locks->source === RateSource::Delta && $locks->value !== null && $locks->value >= 5 && $mysql->tableLocks !== null && $mysql->tableLocks >= self::TABLE_LOCKS && $elapsed !== null) {
            $findings[] = $raise(
                'mysql.table_lock_waits',
                Severity::Warning,
                sprintf('%s of the table locks had to wait %s', Figures::percent($locks->value), Figures::lastSeconds($elapsed)),
                'A query that waits for a table lock waits for another one to be done with the whole table. It mostly happens with MyISAM tables, locked on every write: convert them to InnoDB, or find the long queries that hold the locks.',
                'mysql',
            );
        }

        return $findings;
    }

    private static function judged(Rate $rate, ?int $uptime): bool
    {
        return match ($rate->source) {
            RateSource::Delta => true,
            RateSource::SinceStart => $uptime !== null && $uptime >= self::UPTIME,
            RateSource::Unavailable, RateSource::Pending => false,
        };
    }
}
