<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

/**
 * What a Redis or Valkey server answers to INFO, and what the stack keeps of it.
 */
final class RedisPayloads
{
    /**
     * INFO as the redis extension gives it: flat, numbers as integers.
     *
     * @return array<string, int|float|string>
     */
    public static function info(): array
    {
        return [
            'redis_version' => '7.2.4',
            'redis_mode' => 'standalone',
            'os' => 'Linux 6.6.87 x86_64',
            'tcp_port' => 6379,
            'uptime_in_seconds' => 360_000,
            'executable' => '/data/redis-server',
            'config_file' => '/etc/redis/redis.conf',
            'connected_clients' => 12,
            'used_memory' => 1_500_000_000,
            'used_memory_rss' => 1_550_000_000,
            'used_memory_peak' => 1_600_000_000,
            'maxmemory' => 0,
            'maxmemory_policy' => 'allkeys-lru',
            'rdb_bgsave_in_progress' => 0,
            'rdb_last_save_time' => 1_760_000_000,
            'rdb_changes_since_last_save' => 42,
            'rdb_last_bgsave_status' => 'ok',
            'rdb_last_bgsave_time_sec' => 1,
            'aof_enabled' => 0,
            'aof_rewrite_in_progress' => 0,
            'aof_last_rewrite_time_sec' => -1,
            'aof_last_bgrewrite_status' => 'ok',
            'aof_last_cow_size' => 0,
            'total_connections_received' => 5000,
            'total_commands_processed' => 900_000,
            'instantaneous_ops_per_sec' => 1234,
            'rejected_connections' => 0,
            'expired_keys' => 300,
            'evicted_keys' => 7,
            'evicted_clients' => 0,
            'keyspace_hits' => 9950,
            'keyspace_misses' => 1050,
            'tracking_total_keys' => 0,
            'total_error_replies' => 3,
            'total_reads_processed' => 1,
            'total_writes_processed' => 1,
            'acl_access_denied_auth' => 0,
            'role' => 'master',
            'connected_slaves' => 0,
            'used_cpu_sys' => 12.5,
            'used_cpu_user' => 30.2,
            'db0' => 'keys=1500,expires=1200,avg_ttl=3600000',
            'db1' => 'keys=10,expires=0,avg_ttl=0',
        ];
    }

    /**
     * The same INFO as Predis gives it: by section, every value as a string.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (self::info() as $key => $value) {
            $section = match (true) {
                str_starts_with($key, 'db') => 'Keyspace',
                \in_array($key, ['connected_clients'], true) => 'Clients',
                str_contains($key, 'memory') => 'Memory',
                str_starts_with($key, 'rdb_') || str_starts_with($key, 'aof_') => 'Persistence',
                \in_array($key, ['role', 'connected_slaves'], true) => 'Replication',
                str_starts_with($key, 'used_cpu_') => 'CPU',
                \in_array($key, ['redis_version', 'redis_mode', 'os', 'tcp_port', 'uptime_in_seconds', 'executable', 'config_file'], true) => 'Server',
                default => 'Stats',
            };

            $sections[$section][$key] = $section === 'Keyspace' ? self::database((string) $value) : (string) $value;
        }

        return $sections;
    }

    /**
     * What the stack keeps of info().
     *
     * @return array<string, mixed>
     */
    public static function kept(): array
    {
        return [
            'server' => ['version' => '7.2.4', 'mode' => 'standalone', 'port' => 6379, 'uptime' => 360_000, 'name' => null, 'valkey_version' => null],
            'clients' => ['connected' => 12],
            'memory' => ['used' => 1_500_000_000, 'used_rss' => 1_550_000_000, 'used_peak' => 1_600_000_000, 'max_memory' => 0, 'max_memory_policy' => 'allkeys-lru'],
            'persistence' => ['rdb_bgsave_in_progress' => 0, 'rdb_last_save_time' => 1_760_000_000, 'rdb_changes_since_last_save' => 42, 'rdb_last_bgsave_status' => 'ok', 'rdb_last_bgsave_time' => 1, 'aof_enabled' => 0, 'aof_rewrite_in_progress' => 0, 'aof_last_rewrite_time_sec' => -1, 'aof_last_bgrewrite_status' => 'ok', 'aof_last_cow_size' => 0, 'aof_current_size' => null, 'aof_rewrite_base_size' => null],
            'stats' => ['total_connections_received' => 5000, 'total_commands_processed' => 900_000, 'instantaneous_ops_per_sec' => 1234, 'rejected_connections' => 0, 'expired_keys' => 300, 'evicted_keys' => 7, 'evicted_clients' => 0, 'keyspace_hits' => 9950, 'keyspace_misses' => 1050, 'tracking_total_keys' => 0, 'total_error_replies' => 3, 'total_reads_processed' => 1, 'total_writes_processed' => 1, 'acl_access_denied_auth' => 0],
            'replication' => ['role' => 'master', 'connected_slaves' => 0],
            'cpu' => ['used_sys' => 12.5, 'used_user' => 30.2],
            'databases' => ['db0' => ['keys' => '1500', 'expires' => '1200', 'avg_ttl' => '3600000'], 'db1' => ['keys' => '10', 'expires' => '0', 'avg_ttl' => '0']],
        ];
    }

    /**
     * INFO of a Valkey server, which tells itself Redis 7.2.4.
     *
     * @return array<string, int|float|string>
     */
    public static function valkey(): array
    {
        return ['server_name' => 'valkey', 'valkey_version' => '8.0.1'] + self::info();
    }

    /**
     * Release cycles of Redis as the end of life collector keeps them; at Snapshots::NOW, 7.4 is supported,
     * 7.2 only gets security fixes, 6.2 has ended.
     *
     * @return array{cycles: array<string, array{eol: string, support: string}>}
     */
    public static function redisCycles(): array
    {
        return ['cycles' => [
            '7.4' => ['eol' => '2027-11-30', 'support' => '2026-11-30'],
            '7.2' => ['eol' => '2027-02-28', 'support' => '2026-02-28'],
            '6.2' => ['eol' => '2025-02-28', 'support' => '2024-02-28'],
        ]];
    }

    /**
     * Release cycles of Valkey; at Snapshots::NOW, 8.0 is supported and 7.2 has ended.
     *
     * @return array{cycles: array<string, array{eol: string, support: string}>}
     */
    public static function valkeyCycles(): array
    {
        return ['cycles' => [
            '8.0' => ['eol' => '2027-09-30', 'support' => '2026-09-30'],
            '7.2' => ['eol' => '2025-04-30', 'support' => '2024-10-30'],
        ]];
    }

    /**
     * @return array<string, string>
     */
    private static function database(string $line): array
    {
        $database = [];

        foreach (explode(',', $line) as $pair) {
            [$key, $value] = explode('=', $pair, 2);
            $database[$key] = $value;
        }

        return $database;
    }
}
