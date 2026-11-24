<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Postgresql\PostgresqlActivityCollector as LibraryCollector;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * The counters and the sessions of one database, and the connections of its whole server.
 * No text of a query is kept: a blocked session or the one blocking it may carry values.
 */
final readonly class PostgresqlActivityCollector implements CollectorInterface
{
    private const SERVER_CONNECTIONS = 'SELECT COALESCE(SUM(numbackends), 0) AS server_connections FROM pg_stat_database';

    private const DATABASE_STATS = [
        'numbackends', 'xact_commit', 'xact_rollback', 'blks_read', 'blks_hit',
        'tup_returned', 'tup_fetched', 'tup_inserted', 'tup_updated', 'tup_deleted',
        'conflicts', 'deadlocks', 'temp_files', 'temp_bytes',
    ];

    private const SESSION_FIGURES = [
        'oldest_transaction_seconds', 'idle_in_transaction_count', 'oldest_idle_in_transaction_seconds', 'blocked_count', 'max_wait_seconds',
    ];

    public function __construct(
        private DatabaseAdapterInterface $db,
    ) {}

    /**
     * @return array{
     *     database_stats: array<string, int>,
     *     connections: array<string, int>,
     *     sessions: array<string, mixed>,
     *     server_connections: int,
     * } the connections by state, "unknown" for the sessions the role may not see
     *
     * @throws UnreachableServerException
     */
    public function collect(): array
    {
        $activity = new LibraryCollector($this->db);

        try {
            $activity->boot();
            $data = $activity->collect();
            $server = $this->db->fetchAllAssociative(self::SERVER_CONNECTIONS)[0]['server_connections'] ?? null;
        } catch (\Throwable $e) {
            throw PostgresqlFailure::of($e);
        }

        return [
            'database_stats' => self::integers(\is_array($data['database_stats'] ?? null) ? $data['database_stats'] : [], self::DATABASE_STATS),
            'connections' => self::connections(\is_array($data['connections'] ?? null) ? $data['connections'] : []),
            'sessions' => self::sessions(\is_array($data['sessions'] ?? null) ? $data['sessions'] : []),
            'server_connections' => is_numeric($server) ? (int) $server : 0,
        ];
    }

    public function getVersion(): int
    {
        return (new LibraryCollector($this->db))->getVersion();
    }

    public function getName(): string
    {
        return 'postgresql';
    }

    /**
     * @param array<mixed> $row
     * @param list<string> $keys
     *
     * @return array<string, int>
     */
    private static function integers(array $row, array $keys): array
    {
        $kept = [];

        foreach ($keys as $key) {
            if (is_numeric($row[$key] ?? null)) {
                $kept[$key] = (int) $row[$key];
            }
        }

        return $kept;
    }

    /**
     * @param array<mixed> $connections
     *
     * @return array<string, int>
     */
    private static function connections(array $connections): array
    {
        $kept = [];

        foreach ($connections as $state => $count) {
            if (is_numeric($count)) {
                $kept[(string) $state] = (int) $count;
            }
        }

        return $kept;
    }

    /**
     * @param array<mixed> $sessions
     *
     * @return array<string, mixed>
     */
    private static function sessions(array $sessions): array
    {
        $kept = [];

        foreach (self::SESSION_FIGURES as $key) {
            $kept[$key] = is_numeric($sessions[$key] ?? null) ? (int) $sessions[$key] : null;
        }

        $kept['idle_in_transaction_count'] ??= 0;
        $kept['blocked_count'] ??= 0;
        $kept['blocked_queries'] = [];

        foreach (\is_array($sessions['blocked_queries'] ?? null) ? $sessions['blocked_queries'] : [] as $row) {
            if (!\is_array($row) || !is_numeric($row['blocked_pid'] ?? null) || !is_numeric($row['blocking_pid'] ?? null)) {
                continue;
            }

            $kept['blocked_queries'][] = [
                'blocked_pid' => (int) $row['blocked_pid'],
                'wait_seconds' => is_numeric($row['blocked_wait_seconds'] ?? null) ? (int) $row['blocked_wait_seconds'] : 0,
                'blocking_pid' => (int) $row['blocking_pid'],
                'blocking_state' => \is_string($row['blocking_state'] ?? null) ? $row['blocking_state'] : null,
            ];
        }

        return $kept;
    }
}
