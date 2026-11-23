<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

final class WebServerPayloads
{
    /**
     * What ApacheStatusCollector keeps of a mod_status report: "total_duration" in seconds.
     *
     * @return array<string, mixed>
     */
    public static function apache(int $accesses = 52_000, int $bytes = 1_331_200_000, float $duration = 2_080.0): array
    {
        return [
            'server_version' => 'Apache/2.4.62 (Debian) OpenSSL/3.0.15', 'server_mpm' => 'event', 'uptime' => 1_040_619,
            'load1' => 0.42, 'load5' => 0.37, 'load15' => 0.31,
            'total_accesses' => $accesses, 'total_bytes' => $bytes, 'total_duration' => $duration,
            'requests_per_second' => 0.0499702, 'bytes_per_second' => 1_279, 'bytes_per_request' => 25_600, 'duration_per_request' => 40.0,
            'workers' => ['busy' => 3, 'idle' => 47],
            'scoreboard' => ['_' => 47, 'W' => 1, 'K' => 1, 'R' => 1, '.' => 350],
        ];
    }

    /**
     * @return array{status: array<string, int>}
     */
    public static function nginxStatus(int $accepts = 18_450, int $handled = 18_450, int $requests = 41_200, int $active = 12): array
    {
        return ['status' => ['active' => $active, 'accepts' => $accepts, 'handled' => $handled, 'requests' => $requests, 'reading' => 1, 'writing' => 3, 'waiting' => 8]];
    }

    /**
     * What NginxConfigCollector keeps, on a server with 4 CPUs.
     *
     * @return array{version: ?string, cpu_count: ?int, config: array<string, mixed>}
     */
    public static function nginxConfig(): array
    {
        return [
            'version' => '1.26.2',
            'cpu_count' => 4,
            'config' => [
                'config_path' => '/etc/nginx/nginx.conf',
                'worker_processes' => 'auto',
                'worker_connections' => 768,
                'keepalive_timeout' => 65,
                'gzip' => 'on',
                'server_tokens' => 'off',
                'ssl_protocols' => 'TLSv1.2 TLSv1.3',
            ],
        ];
    }

    /**
     * What NginxConfigCollector keeps when "nginx -T" prints nothing, as it does when PHP does not run as root.
     *
     * @return array{version: ?string, cpu_count: ?int, config: array<string, mixed>}
     */
    public static function nginxConfigWithoutSettings(): array
    {
        $config = self::nginxConfig();
        $config['config'] = array_fill_keys(array_keys($config['config']), null);

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public static function frankenphpClassic(): array
    {
        return ['mode' => 'classic', 'busy_threads' => 3, 'total_threads' => 16, 'queue_depth' => 0, 'workers' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public static function frankenphpWorker(): array
    {
        return ['mode' => 'worker', 'busy_threads' => 13, 'total_threads' => 16, 'queue_depth' => 0, 'workers' => [
            ['name' => '/app/public/index.php', 'total_workers' => 8, 'busy_workers' => 2, 'worker_request_time' => 125.5, 'worker_request_count' => 10_000, 'ready_workers' => 8, 'worker_crashes' => 1, 'worker_restarts' => 3, 'worker_queue_depth' => 0],
            ['name' => 'api', 'total_workers' => 4, 'busy_workers' => 4, 'worker_request_time' => 40.0, 'worker_request_count' => 2_000, 'ready_workers' => 4, 'worker_crashes' => 0, 'worker_restarts' => 0, 'worker_queue_depth' => 2],
        ]];
    }

    /**
     * Release cycles at Snapshots::NOW: Apache 2.4 and Nginx 1.28 are supported, Nginx 1.27 only gets security fixes,
     * Nginx 1.26 has ended.
     *
     * @return array{cycles: array<string, array{eol: bool|string, support: bool|string|null}>}
     */
    public static function cycles(): array
    {
        return ['cycles' => [
            '2.4' => ['eol' => false, 'support' => true],
            '1.28' => ['eol' => '2027-04-30', 'support' => '2027-04-30'],
            '1.27' => ['eol' => '2026-12-31', 'support' => '2026-04-30'],
            '1.26' => ['eol' => '2025-04-23', 'support' => '2025-04-23'],
        ]];
    }
}
