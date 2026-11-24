<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Metric\EndOfLife;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\Ratios;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;

final readonly class PostgresqlReading
{
    /** a variation with fewer blocks tells nothing about the ratio, and may hold little more than the reads of the stack itself */
    public const CACHE_BLOCKS = 10_000;

    /** the statistics of a database survive a restart of the server */
    private const SINCE_RESET = 'since the statistics were reset';

    private const TRANSACTIONS = ['xact_commit', 'xact_rollback'];
    private const WRITES = ['tup_inserted', 'tup_updated', 'tup_deleted'];

    /**
     * @param ?string                        $version                 the numbers ahead of the build, such as "16.4" for "16.4 (Debian 16.4-1.pgdg120+1)"
     * @param ?SupportStatus                 $support                 null without a cycle for this version, as for a development version or a server that only speaks its protocol, such as CockroachDB
     * @param ?int                           $serverConnections       every database of the server included
     * @param array<string, int>             $connectionsByState      the connections of this database, "unknown" for those whose state the role may not see
     * @param bool                           $limitedRole             the role may not read the sessions of other roles; a background worker does not count
     * @param ?int                           $hiddenSessions          the sessions of other roles in this database that the role may not read
     * @param Rate                           $cacheHitRate            the variation between the two samples when it holds CACHE_BLOCKS, else since the statistics were reset
     * @param ?int                           $cacheBlocks             the blocks the rate shown counts
     * @param ?int                           $transactions            committed or rolled back between the last two samples
     * @param ?int                           $temporaryBytes          written to the temporary files, since the statistics were reset
     * @param ?int                           $oldestIdleInTransaction in seconds
     * @param ?int                           $oldestTransaction       in seconds
     * @param ?int                           $blockedSessions         each session once, though it may wait for several
     * @param ?int                           $longestBlock            in seconds, among the waits the role may see
     * @param list<PostgresqlBlockedSession> $blocked                 one per session waiting and session it waits for
     * @param array<string, string|int>      $settings                the settings read, by name, sizes in bytes
     * @param list<MysqlSlowQuery>           $slowQueries             the statements that read or write rows, their values replaced
     * @param ?int                           $hiddenStatements        the statements whose text the role may not see
     */
    private function __construct(
        public ?string $version,
        public ?SupportStatus $support,
        public ?int $serverConnections,
        public ?int $maxConnections,
        public ?float $connectionsPercent,
        public ?int $databaseConnections,
        public array $connectionsByState,
        public bool $limitedRole,
        public ?int $hiddenSessions,
        public Rate $cacheHitRate,
        public ?int $cacheBlocks,
        public Rate $transactionsPerSecond,
        public Rate $rollbackRate,
        public ?int $transactions,
        public Rate $rowsWrittenPerSecond,
        public Rate $diskReadsPerSecond,
        public ?int $temporaryFiles,
        public ?int $temporaryBytes,
        public ?int $deadRows,
        public ?int $liveRows,
        public ?float $deadRowsPercent,
        public ?string $schema,
        public ?int $tables,
        public ?int $databaseSize,
        public ?int $tablesSize,
        public ?int $indexesSize,
        public ?int $idleInTransaction,
        public ?int $oldestIdleInTransaction,
        public ?int $oldestTransaction,
        public ?int $blockedSessions,
        public ?int $longestBlock,
        public array $blocked,
        public ?bool $autovacuum,
        public ?bool $trackCounts,
        public array $settings,
        public array $slowQueries,
        public ?int $hiddenStatements,
    ) {}

    public static function of(ComponentSnapshot $snapshot, \DateTimeImmutable $today): self
    {
        $activity = $snapshot->data('postgresql');
        $settings = $snapshot->data('postgresql.settings');
        $database = $snapshot->data('postgresql.database');
        $slowQueries = $snapshot->data('postgresql.slow_queries');
        $delta = $snapshot->delta('postgresql');
        $stats = self::array($activity['database_stats'] ?? null) ?? [];
        $connections = self::array($activity['connections'] ?? null);
        $sessions = self::array($activity['sessions'] ?? null) ?? [];
        $tables = self::array($database['tables'] ?? null) ?? [];

        $fullVersion = Values::string($settings['server_version'] ?? null);
        $version = $fullVersion !== null && preg_match('/^\d+(?:\.\d+)*/', $fullVersion, $matches) === 1 ? $matches[0] : null;

        $serverConnections = Values::int($activity['server_connections'] ?? null);
        $maxConnections = Values::int($settings['max_connections'] ?? null);
        $hiddenSessions = Values::int($activity['hidden_sessions'] ?? null);

        $hits = Values::int($stats['blks_hit'] ?? null);
        $reads = Values::int($stats['blks_read'] ?? null);
        $recentHitRate = Ratios::share($delta, 'database_stats.blks_hit', 'database_stats.blks_read', null, null);
        $cacheHitRate = $recentHitRate->total !== null && $recentHitRate->total >= self::CACHE_BLOCKS
            ? $recentHitRate
            : Ratios::share(null, 'database_stats.blks_hit', 'database_stats.blks_read', $hits, $reads, self::SINCE_RESET);

        $commits = Values::int($stats['xact_commit'] ?? null);
        $rollbacks = Values::int($stats['xact_rollback'] ?? null);
        $transactions = self::variation($delta, self::TRANSACTIONS);

        $dead = Values::int($tables['dead_tuples'] ?? null);
        $live = Values::int($tables['live_tuples'] ?? null);
        $blocked = self::blocked($sessions['blocked_queries'] ?? null);
        $blockedCount = Values::int($sessions['blocked_count'] ?? null);
        $waits = array_filter(array_map(static fn(PostgresqlBlockedSession $session): ?int => $session->waitSeconds, $blocked), is_int(...));

        return new self(
            $version,
            self::isPostgresql($fullVersion) ? EndOfLife::status($snapshot->data('eol.postgresql'), $version, $today) : null,
            $serverConnections,
            $maxConnections,
            Values::percent($serverConnections, $maxConnections),
            Values::int($stats['numbackends'] ?? null),
            self::states($connections ?? []),
            $hiddenSessions !== null && $hiddenSessions >= 1,
            $hiddenSessions,
            $cacheHitRate,
            Values::int($cacheHitRate->total),
            self::perSecond($delta, $stats, self::TRANSACTIONS, 'tx/s'),
            Ratios::share($delta, 'database_stats.xact_rollback', 'database_stats.xact_commit', $rollbacks, $commits, self::SINCE_RESET),
            $transactions,
            self::perSecond($delta, $stats, self::WRITES, 'rows/s'),
            Ratios::perSecond($delta, 'database_stats.blks_read', 'blocks/s', $reads),
            Values::int($stats['temp_files'] ?? null),
            Values::int($stats['temp_bytes'] ?? null),
            $dead,
            $live,
            $dead === null || $live === null ? null : Values::percent($dead, $dead + $live),
            Values::string($database['schema'] ?? null),
            Values::int($tables['table_count'] ?? null),
            Values::int($database['db_size'] ?? null),
            Values::int($tables['total_size'] ?? null),
            Values::int($tables['indexes_size'] ?? null),
            Values::int($sessions['idle_in_transaction_count'] ?? null),
            Values::int($sessions['oldest_idle_in_transaction_seconds'] ?? null),
            Values::int($sessions['oldest_transaction_seconds'] ?? null),
            $blockedCount === null && $blocked === [] ? null : max($blockedCount ?? 0, count(array_unique(array_map(static fn(PostgresqlBlockedSession $session): int => $session->pid, $blocked)))),
            $waits === [] ? null : max($waits),
            $blocked,
            Values::bool($settings['autovacuum'] ?? null),
            Values::bool($settings['track_counts'] ?? null),
            self::settings($settings),
            self::slowQueries($slowQueries['queries'] ?? null),
            Values::int($slowQueries['hidden'] ?? null),
        );
    }

    /**
     * PostgreSQL numbers its versions "major.minor" from 10 on, "9.6.24" before. The servers that only speak its
     * protocol give another shape: "13.0.0" for CockroachDB, "11.2-YB-2.20.0.0" for YugabyteDB.
     */
    private static function isPostgresql(?string $version): bool
    {
        if ($version === null || preg_match('/^(\d+)\.\d+(\.\d+)?(?:\s|$)/', $version, $matches) !== 1) {
            return false;
        }

        return ((int) $matches[1] >= 10) === !isset($matches[2]);
    }

    /**
     * @param list<string> $keys counters of the database
     */
    private static function variation(?Delta $delta, array $keys): ?int
    {
        $sum = 0;

        foreach ($keys as $key) {
            $value = $delta?->getValue('database_stats.' . $key);

            if ($value === null) {
                return null;
            }

            $sum += $value;
        }

        return Values::int($sum);
    }

    /**
     * The sum of the counters, per second between the last two samples.
     *
     * @param array<mixed> $stats
     * @param list<string> $keys
     */
    private static function perSecond(?Delta $delta, array $stats, array $keys, string $unit): Rate
    {
        $variation = self::variation($delta, $keys);

        if ($delta !== null && $variation !== null) {
            return new Rate(round($variation / $delta->elapsed, 2), RateSource::Delta, $unit);
        }

        foreach ($keys as $key) {
            if (Values::int($stats[$key] ?? null) === null) {
                return Rate::unavailable($unit);
            }
        }

        return $delta === null ? Rate::pending($unit) : Rate::unavailable($unit);
    }

    /**
     * @return ?array<mixed>
     */
    private static function array(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * @param array<mixed> $connections
     *
     * @return array<string, int>
     */
    private static function states(array $connections): array
    {
        $states = [];

        foreach ($connections as $state => $count) {
            $count = Values::int($count);

            if ($count !== null) {
                $states[(string) $state] = $count;
            }
        }

        return $states;
    }

    /**
     * @return list<PostgresqlBlockedSession>
     */
    private static function blocked(mixed $rows): array
    {
        $blocked = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $pid = is_array($row) ? Values::int($row['blocked_pid'] ?? null) : null;

            if ($pid === null) {
                continue;
            }

            $blocked[] = new PostgresqlBlockedSession(
                $pid,
                Values::int($row['wait_seconds'] ?? null),
                Values::int($row['blocking_pid'] ?? null),
                Values::string($row['blocking_state'] ?? null),
            );
        }

        return $blocked;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, string|int>
     */
    private static function settings(array $settings): array
    {
        $kept = [];

        foreach ($settings as $name => $value) {
            if ((is_string($value) || is_int($value)) && $name !== 'server_version') {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    /**
     * @return list<MysqlSlowQuery>
     */
    private static function slowQueries(mixed $queries): array
    {
        $slowQueries = [];

        foreach (is_array($queries) ? $queries : [] as $query) {
            $text = is_array($query) ? Values::string($query['text'] ?? null) : null;
            $executions = is_array($query) ? Values::int($query['executions'] ?? null) : null;

            if ($text === null || $executions === null) {
                continue;
            }

            $slowQueries[] = new MysqlSlowQuery(
                $text,
                $executions,
                Values::int($query['average_ms'] ?? null),
                Values::int($query['max_ms'] ?? null),
                Values::int($query['total_ms'] ?? null),
            );
        }

        return $slowQueries;
    }
}
