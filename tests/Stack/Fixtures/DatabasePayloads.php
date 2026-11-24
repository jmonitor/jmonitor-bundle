<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

final class DatabasePayloads
{
    /**
     * What MysqlStatusCollector keeps.
     *
     * @return array<string, string>
     */
    public static function mysqlStatus(): array
    {
        return [
            'Uptime' => '86400',
            'Threads_connected' => '30',
            'Threads_running' => '3',
            'Threads_created' => '7',
            'Connections' => '1100',
            'Questions' => '1150',
            'Aborted_connects' => '2',
            'Aborted_clients' => '1',
            'Created_tmp_tables' => '150',
            'Created_tmp_disk_tables' => '20',
            'Max_used_connections' => '45',
            'Slow_queries' => '12',
            'Innodb_buffer_pool_read_requests' => '110000',
            'Innodb_buffer_pool_reads' => '1100',
            'Innodb_buffer_pool_pages_total' => '8192',
            'Innodb_buffer_pool_pages_free' => '1024',
            'Innodb_page_size' => '16384',
            'Innodb_data_reads' => '550',
            'Innodb_data_writes' => '220',
            'Table_locks_waited' => '3',
            'Table_locks_immediate' => '1097',
        ];
    }

    /**
     * What MysqlVariablesCollector keeps: no path of the server.
     *
     * @return array<string, string|int>
     */
    public static function mysqlVariables(): array
    {
        return [
            'version' => '8.4.0',
            'version_comment' => 'MySQL Community Server - GPL',
            'max_connections' => '100',
            'innodb_buffer_pool_size' => '134217728',
            'slow_query_log' => 'ON',
            'long_query_time' => '1.000000',
            'tmp_table_size' => '16777216',
            'max_heap_table_size' => '16777216',
            'thread_cache_size' => '9',
            'table_open_cache' => '4000',
            'wait_timeout' => '28800',
            'log_bin' => 'ON',
            'character_set_server' => 'utf8mb4',
            'collation_server' => 'utf8mb4_0900_ai_ci',
            'time_zone' => 'SYSTEM',
            'system_time_zone' => 'UTC',
            'join_buffer_size' => '262144',
            'sort_buffer_size' => '262144',
            'timestamp' => 1760000000,
        ];
    }

    /**
     * What MysqlSizesCollector keeps, in bytes.
     *
     * @return array{data_length: ?int, index_length: ?int}
     */
    public static function mysqlSizes(): array
    {
        return ['data_length' => 52_428_800, 'index_length' => 10_485_760];
    }

    /**
     * What MysqlSlowQueriesCollector keeps.
     *
     * @return array{queries: list<array{text: string, executions: int, average_ms: int, max_ms: int, total_ms: int}>}
     */
    public static function mysqlSlowQueries(): array
    {
        return ['queries' => [
            ['text' => 'SELECT * FROM `order` WHERE `status` = ?', 'executions' => 120, 'average_ms' => 300, 'max_ms' => 1200, 'total_ms' => 36000],
        ]];
    }

    /**
     * What PostgresqlActivityCollector keeps.
     *
     * @return array<string, mixed>
     */
    public static function postgresqlActivity(): array
    {
        return [
            'database_stats' => [
                'numbackends' => 7,
                'xact_commit' => 125_000,
                'xact_rollback' => 120,
                'blks_read' => 4_000,
                'blks_hit' => 396_000,
                'tup_returned' => 900_000,
                'tup_fetched' => 300_000,
                'tup_inserted' => 5_000,
                'tup_updated' => 2_500,
                'tup_deleted' => 300,
                'conflicts' => 0,
                'deadlocks' => 1,
                'temp_files' => 3,
                'temp_bytes' => 6_291_456,
            ],
            'connections' => ['active' => 2, 'idle' => 4, 'idle in transaction' => 1],
            'sessions' => [
                'oldest_transaction_seconds' => 340,
                'idle_in_transaction_count' => 1,
                'oldest_idle_in_transaction_seconds' => 340,
                'blocked_count' => 1,
                'max_wait_seconds' => 45,
                'blocked_queries' => [
                    ['blocked_pid' => 4242, 'wait_seconds' => 45, 'blocking_pid' => 4100, 'blocking_state' => 'idle in transaction'],
                ],
            ],
            'server_connections' => 37,
        ];
    }

    /**
     * The answers of a PostgreSQL 17 server to the queries of PostgresqlActivityCollector, as PDO gives them.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function postgresqlActivityRows(): array
    {
        return [
            'server_connections' => [['server_connections' => 37]],
            'server_version_num' => [['server_version_num' => '170002']],
            'xact_commit' => [[
                'numbackends' => 7,
                'xact_commit' => 125_000,
                'xact_rollback' => '120',
                'blks_read' => 4_000,
                'blks_hit' => 396_000,
                'tup_returned' => 900_000,
                'tup_fetched' => 300_000,
                'tup_inserted' => 5_000,
                'tup_updated' => 2_500,
                'tup_deleted' => 300,
                'conflicts' => 0,
                'deadlocks' => 1,
                'temp_files' => 3,
                'temp_bytes' => '6291456',
            ]],
            'pg_stat_checkpointer' => [['checkpoints_timed' => 120, 'checkpoints_req' => 3, 'buffers_checkpoint' => 9_000]],
            'pg_stat_bgwriter' => [['buffers_clean' => 0, 'maxwritten_clean' => 0, 'buffers_alloc' => 4_100]],
            'GROUP BY state' => [['state' => 'active', 'count' => 2], ['state' => 'idle', 'count' => 4], ['state' => 'idle in transaction', 'count' => 1]],
            'xact_start IS NOT NULL' => [['oldest_transaction_seconds' => 340]],
            "state = 'idle in transaction'" => [['cnt' => 1, 'oldest_seconds' => 340]],
            'pg_blocking_pids' => [[
                'blocked_pid' => 4242,
                'blocked_wait_seconds' => 45,
                'blocked_query_sample' => "UPDATE account SET password = 's3cret' WHERE id = 7",
                'blocking_pid' => 4100,
                'blocking_query_sample' => "UPDATE account SET email = 'jane@example.com' WHERE id = 7",
                'blocking_state' => 'idle in transaction',
            ]],
        ];
    }

    /**
     * What PostgresqlSettingsCollector keeps: sizes in bytes.
     *
     * @return array<string, string|int>
     */
    public static function postgresqlSettings(): array
    {
        return [
            'server_version' => '17.2',
            'max_connections' => '100',
            'shared_buffers' => 134_217_728,
            'effective_cache_size' => 4_294_967_296,
            'work_mem' => 4_194_304,
            'autovacuum' => 'on',
            'track_counts' => 'on',
        ];
    }

    /**
     * The rows pg_settings answers for postgresqlSettings().
     *
     * @return list<array{name: string, setting: string, unit: ?string}>
     */
    public static function postgresqlSettingsRows(): array
    {
        return [
            ['name' => 'server_version', 'setting' => '17.2', 'unit' => null],
            ['name' => 'max_connections', 'setting' => '100', 'unit' => null],
            ['name' => 'shared_buffers', 'setting' => '16384', 'unit' => '8kB'],
            ['name' => 'effective_cache_size', 'setting' => '524288', 'unit' => '8kB'],
            ['name' => 'work_mem', 'setting' => '4096', 'unit' => 'kB'],
            ['name' => 'autovacuum', 'setting' => 'on', 'unit' => null],
            ['name' => 'track_counts', 'setting' => 'on', 'unit' => null],
        ];
    }

    /**
     * What PostgresqlDatabaseCollector keeps, sizes in bytes.
     *
     * @return array{schema: string, db_size: ?int, tables: array<string, ?int>}
     */
    public static function postgresqlDatabase(string $schema = 'public'): array
    {
        return [
            'schema' => $schema,
            'db_size' => 83_886_080,
            'tables' => [
                'table_count' => 12,
                'live_tuples' => 250_000,
                'dead_tuples' => 4_000,
                'seq_scans' => 300,
                'idx_scans' => 90_000,
                'total_size' => 62_914_560,
                'indexes_size' => 20_971_520,
            ],
        ];
    }

    /**
     * What PostgresqlSlowQueriesCollector keeps.
     *
     * @return array{queries: list<array{text: string, executions: int, average_ms: int, max_ms: int, total_ms: int}>, hidden: int}
     */
    public static function postgresqlSlowQueries(): array
    {
        return ['queries' => [
            ['text' => 'SELECT * FROM customer WHERE email = $1', 'executions' => 120, 'average_ms' => 300, 'max_ms' => 1200, 'total_ms' => 36000],
        ], 'hidden' => 0];
    }

    /**
     * A row of pg_stat_statements, as the library selects it.
     *
     * @return array<string, string|int>
     */
    public static function statement(string $text, int $calls = 1, float $averageMs = 1.0, ?float $maxMs = null, ?float $totalMs = null): array
    {
        return [
            'query_sample' => $text,
            'exec_count' => $calls,
            'total_time_ms' => number_format($totalMs ?? $averageMs * $calls, 2, '.', ''),
            'avg_time_ms' => number_format($averageMs, 2, '.', ''),
            'max_time_ms' => number_format($maxMs ?? $averageMs, 2, '.', ''),
            'stddev_time_ms' => '0.00',
            'rows' => $calls,
            'shared_blks_hit' => 10,
            'shared_blks_read' => 0,
        ];
    }

    /**
     * The rows SHOW GLOBAL STATUS and SHOW GLOBAL VARIABLES answer.
     *
     * @param array<string, string|int> $values
     *
     * @return list<array{Variable_name: string, Value: string}>
     */
    public static function rows(array $values): array
    {
        $rows = [];

        foreach ($values as $name => $value) {
            $rows[] = ['Variable_name' => (string) $name, 'Value' => (string) $value];
        }

        return $rows;
    }

    /**
     * A row of performance_schema.events_statements_summary_by_digest, as the library selects it.
     *
     * @return array<string, string|int>
     */
    public static function digest(string $text, int $executions = 1, int $averageMs = 1, ?int $maxMs = null, ?int $totalMs = null): array
    {
        return [
            'query_sample' => $text,
            'exec_count' => (string) $executions,
            'total_time_ms' => (string) ($totalMs ?? $averageMs * $executions),
            'avg_time_ms' => (string) $averageMs,
            'max_time_ms' => (string) ($maxMs ?? $averageMs),
        ];
    }
}
