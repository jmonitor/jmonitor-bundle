<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Reading\PostgresqlReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class PostgresqlChecks
{
    /** the cumulated counters of a database that has barely been used tell nothing about its cache */
    private const CACHE_BLOCKS_SINCE_RESET = 100_000;

    /** fewer transactions between two samples tell nothing about their ratio */
    private const TRANSACTIONS = 100;

    /** a small schema may be mostly dead rows without costing anything */
    private const DEAD_ROWS = 10_000;

    private const IDLE_IN_TRANSACTION_SECONDS = 300;
    private const BLOCKED_SECONDS = 30;

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    public static function of(PostgresqlReading $postgresql, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        $findings = [];
        $elapsed = $snapshot->delta('postgresql')?->elapsed;

        if ($postgresql->connectionsPercent !== null && $postgresql->connectionsPercent >= 80) {
            $findings[] = $raise(
                'postgresql.connections',
                $postgresql->connectionsPercent >= 95 ? Severity::Critical : Severity::Warning,
                sprintf('The PostgreSQL server holds %s of its connections (%d of %d)', Figures::percent($postgresql->connectionsPercent), $postgresql->serverConnections, $postgresql->maxConnections),
                'Past max_connections, PostgreSQL refuses new connections, and the requests that need the database fail. The count is that of the whole server, every database included. Find what holds the connections, such as long transactions or processes that keep theirs open, put a connection pooler in front of the server, or raise max_connections if its memory allows it.',
                'postgresql',
                'postgresql.settings',
            );
        }

        $hitRate = $postgresql->cacheHitRate;
        $judged = $hitRate->source === RateSource::Delta
            || ($hitRate->source === RateSource::SinceStart && $postgresql->cacheBlocks !== null && $postgresql->cacheBlocks >= self::CACHE_BLOCKS_SINCE_RESET);

        if ($judged && $hitRate->value !== null && $hitRate->value < 95) {
            $findings[] = $raise(
                'postgresql.cache_hit_rate',
                Severity::Warning,
                sprintf('The cache hit rate is %s %s', Figures::percent($hitRate->value), Figures::span($hitRate, $elapsed)),
                'Every miss reads a block from outside shared_buffers, from the cache of the system or from the disk, and the queries wait for it. Raise shared_buffers, as far as the memory of the server allows it, so that the data the application uses fits in it, or find the queries that read whole tables.',
                'postgresql',
            );
        }

        $rollbacks = $postgresql->rollbackRate;

        if ($rollbacks->source === RateSource::Delta && $rollbacks->value !== null && $rollbacks->value >= 10 && $postgresql->transactions !== null && $postgresql->transactions >= self::TRANSACTIONS && $elapsed !== null) {
            $findings[] = $raise(
                'postgresql.rollbacks',
                Severity::Warning,
                sprintf('%s of the transactions were rolled back %s', Figures::percent($rollbacks->value), Figures::lastSeconds($elapsed)),
                'A transaction rolled back has its work undone. Many of them usually mean errors, such as failed constraints, deadlocks or timeouts. Look at the logs of the application and of PostgreSQL.',
                'postgresql',
            );
        }

        if ($postgresql->deadRowsPercent !== null && $postgresql->deadRowsPercent >= 20 && $postgresql->deadRows !== null && $postgresql->deadRows >= self::DEAD_ROWS) {
            $findings[] = $raise(
                'postgresql.dead_rows',
                Severity::Warning,
                sprintf(
                    '%s of the rows of %s are dead (%d of %d)',
                    Figures::percent($postgresql->deadRowsPercent),
                    $postgresql->schema === null ? 'the schema' : 'the schema ' . $postgresql->schema,
                    $postgresql->deadRows,
                    $postgresql->deadRows + (int) $postgresql->liveRows,
                ),
                'An update or a delete leaves the former version of the row behind until VACUUM removes it. Until then, the tables and their indexes grow, and the queries read more. Check that autovacuum keeps up with the writes: lower autovacuum_vacuum_scale_factor for the busiest tables, or run VACUUM on them.',
                'postgresql.database',
            );
        }

        if (!$postgresql->limitedRole && $postgresql->oldestIdleInTransaction !== null && $postgresql->oldestIdleInTransaction >= self::IDLE_IN_TRANSACTION_SECONDS) {
            $idle = (int) $postgresql->idleInTransaction;
            $findings[] = $raise(
                'postgresql.idle_in_transaction',
                Severity::Warning,
                $idle > 1
                    ? sprintf('%d sessions are idle in a transaction, the oldest for %s', $idle, Figures::duration($postgresql->oldestIdleInTransaction))
                    : sprintf('A session has been idle in a transaction for %s', Figures::duration($postgresql->oldestIdleInTransaction)),
                'A transaction left open holds its locks, which can block other sessions, and keeps VACUUM from removing the dead rows of the whole database. Find the code that opens a transaction and does not end it, or set idle_in_transaction_session_timeout.',
                'postgresql',
            );
        }

        if (!$postgresql->limitedRole && $postgresql->longestBlock !== null && $postgresql->longestBlock >= self::BLOCKED_SECONDS) {
            $waiting = max((int) $postgresql->blockedSessions, count($postgresql->blocked));
            $findings[] = $raise(
                'postgresql.blocked_sessions',
                Severity::Warning,
                $waiting > 1
                    ? sprintf('%d sessions are waiting for a lock, the longest for %s', $waiting, Figures::duration($postgresql->longestBlock))
                    : sprintf('A session has been waiting for a lock for %s', Figures::duration($postgresql->longestBlock)),
                'A session that waits for a lock waits for another one to end its transaction, and the request behind it waits too, or fails on a timeout. Look up the session that blocks it in pg_stat_activity, and find the code that keeps its transaction open.',
                'postgresql',
            );
        }

        if ($postgresql->autovacuum === false || $postgresql->trackCounts === false) {
            $findings[] = $raise(
                'postgresql.autovacuum_off',
                Severity::Critical,
                match (true) {
                    $postgresql->autovacuum === false && $postgresql->trackCounts === false => 'autovacuum and track_counts are off',
                    $postgresql->autovacuum === false => 'autovacuum is off',
                    default => 'track_counts is off, which stops autovacuum',
                },
                'Without autovacuum, which needs track_counts to know what to clean, nothing removes the dead rows nor renews the statistics of the planner on its own: the tables grow and the queries slow down. Turn both back on.',
                'postgresql.settings',
            );
        }

        if ($postgresql->limitedRole && $postgresql->hiddenSessions !== null) {
            $findings[] = $raise(
                'postgresql.limited_role',
                Severity::Info,
                sprintf('The role of this connection cannot see the state of %d %s', $postgresql->hiddenSessions, $postgresql->hiddenSessions === 1 ? 'session' : 'sessions'),
                'PostgreSQL only shows a role without privilege the state of its own sessions, so the transactions left idle and the sessions waiting for a lock are not judged here. Grant it the pg_monitor role to see them all.',
                'postgresql',
            );
        }

        return $findings;
    }
}
