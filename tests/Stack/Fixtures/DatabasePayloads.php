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
