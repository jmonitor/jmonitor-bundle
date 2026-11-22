<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;

final class Snapshots
{
    /** 2026-05-28 */
    public const NOW = 1_780_000_000;
    public const STALE_AGE = 3_600;
    public const FAILURE = 'It could not be read.';

    /**
     * A Fresh value is read at NOW, a Stale one STALE_AGE before; a Failed, Pending or Busy result has no data.
     *
     * @param array<string, array<string, mixed>> $dataByCollector
     * @param array<string, ResultState>          $states          Fresh when not given
     * @param array<string, Delta>                $deltas
     */
    public static function of(Component $component, array $dataByCollector, array $states = [], array $deltas = []): ComponentSnapshot
    {
        $results = [];

        foreach (array_keys($dataByCollector + $states) as $name) {
            $state = $states[$name] ?? ResultState::Fresh;
            $results[$name] = match ($state) {
                ResultState::Fresh, ResultState::Stale => new CollectorResult(
                    $name,
                    $dataByCollector[$name] ?? [],
                    $state,
                    collectedAt: $state === ResultState::Fresh ? self::NOW : self::NOW - self::STALE_AGE,
                    delta: $deltas[$name] ?? null,
                    expiresAt: $state === ResultState::Fresh ? self::NOW + 60 : self::NOW - self::STALE_AGE + 60,
                ),
                ResultState::Failed => new CollectorResult($name, [], $state, self::FAILURE, expiresAt: self::NOW + 60),
                ResultState::Pending, ResultState::Busy => new CollectorResult($name, [], $state),
            };
        }

        return new ComponentSnapshot($component, $results);
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     */
    public static function delta(array $previous, array $current, int $elapsed = 60): Delta
    {
        return new Delta(Delta::flatten($previous), Delta::flatten($current), $elapsed);
    }

    /**
     * One instance without id per snapshot, or the instance states given.
     */
    public static function stack(ComponentSnapshot|InstanceState ...$instances): StackState
    {
        $byComponent = [];

        foreach ($instances as $instance) {
            if ($instance instanceof ComponentSnapshot) {
                $instance = new InstanceState(null, null, null, $instance);
            }

            $component = $instance->snapshot->component ?? throw new \LogicException('An instance without snapshot needs its component: use state().');
            $byComponent[$component->value][] = $instance;
        }

        return self::state($byComponent);
    }

    /**
     * @param array<string, list<InstanceState>> $instancesByComponent keyed by component value
     */
    public static function state(array $instancesByComponent): StackState
    {
        $components = [];

        foreach (Component::cases() as $component) {
            if (isset($instancesByComponent[$component->value])) {
                $instances = $instancesByComponent[$component->value];
                $unread = count(array_filter($instances, static fn(InstanceState $instance): bool => $instance->snapshot === null));
                $components[] = new ComponentState($component, $instances, $unread);
            }
        }

        return new StackState(null, self::NOW, CacheState::Working, $components, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function system(): array
    {
        return [
            'disk' => ['total' => 100_000_000_000, 'free' => 25_000_000_000],
            'cpu' => ['cores' => 4, 'load' => 62, 'load1' => 2.48, 'load5' => 1.1, 'load15' => 0.9],
            'ram' => ['total' => 8_000_000_000, 'available' => 2_000_000_000],
            'os' => ['pretty_name' => 'Debian GNU/Linux 12 (bookworm)', 'uptime' => 86_400],
            'time' => 1_760_000_000,
            'timezone' => 'Europe/Paris',
            'hostname' => 'web-1',
            'cgroup' => ['memory_limit' => null, 'cpu_quota' => null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function php(): array
    {
        return [
            'version' => '8.4.23',
            'sapi_name' => 'fpm-fcgi',
            'ini_file' => '/etc/php/8.4/fpm/php.ini',
            'ini_files' => ['/etc/php/8.4/fpm/conf.d/10-opcache.ini'],
            'expose_php' => false,
            'memory_limit' => '128M',
            'max_execution_time' => 30,
            'max_input_time' => 60,
            'max_input_vars' => 1000,
            'realpath_cache_used_size' => 120000,
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => 600,
            'post_max_size' => '8M',
            'upload_max_filesize' => '2M',
            'display_errors' => '0',
            'display_startup_errors' => false,
            'log_errors' => true,
            'error_log' => '/var/log/php/error.log',
            'error_reporting' => 32767,
            'date.timezone' => 'UTC',
            'loaded_extensions' => ['Core', 'json', 'Zend OPcache', 'apcu'],
            'opcache' => [
                'config' => [
                    'directives' => [
                        'opcache.enable' => true,
                        'opcache.enable_cli' => false,
                        'opcache.memory_consumption' => 134217728,
                        'opcache.interned_strings_buffer' => 16,
                        'opcache.max_accelerated_files' => 20000,
                        'opcache.validate_timestamps' => false,
                        'opcache.revalidate_freq' => 2,
                        'opcache.jit' => 'tracing',
                        'opcache.jit_buffer_size' => 67108864,
                    ],
                    'version' => ['version' => '8.4.23', 'opcache_product_name' => 'Zend OPcache'],
                ],
                'status' => [
                    'opcache_enabled' => true,
                    'cache_full' => false,
                    'memory_usage' => ['used_memory' => 60000000, 'free_memory' => 30000000, 'wasted_memory' => 10000000, 'current_wasted_percentage' => 10.0],
                    'opcache_statistics' => ['num_cached_scripts' => 1234, 'num_cached_keys' => 1500, 'max_cached_keys' => 32531, 'hits' => 1090, 'misses' => 110, 'opcache_hit_rate' => 90.8, 'oom_restarts' => 0, 'hash_restarts' => 0, 'manual_restarts' => 1],
                ],
            ],
            'apcu' => [
                'config' => ['apc.enabled' => true, 'apc.enable_cli' => false, 'apc.shm_size' => '32M', 'apc.ttl' => 0],
                'cache_info' => ['num_slots' => 4099, 'ttl' => 0, 'num_hits' => 900, 'num_misses' => 100, 'num_inserts' => 120, 'num_entries' => 42, 'expunges' => 0, 'start_time' => 1760000000, 'mem_size' => 25165824],
                'sma_info' => ['num_seg' => 1, 'seg_size' => 33554432, 'avail_mem' => 8388608],
            ],
            'fpm' => [
                'pool' => 'www',
                'process-manager' => 'dynamic',
                'start-time' => 1760000000,
                'start-since' => 3600,
                'accepted-conn' => 5000,
                'listen-queue' => 0,
                'max-listen-queue' => 2,
                'listen-queue-len' => 511,
                'idle-processes' => 6,
                'active-processes' => 2,
                'total-processes' => 8,
                'max-active-processes' => 7,
                'max-children-reached' => 3,
                'slow-requests' => 1,
                'memory-peak' => 67108864,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function symfony(): array
    {
        return [
            'env' => 'prod',
            'debug' => false,
            'version' => '7.4.2',
            'bundles' => ['FrameworkBundle', 'TwigBundle', 'JmonitorBundle'],
        ];
    }

    /**
     * Release cycles as the end of life collectors keep them; at NOW, 8.4 and 7.4 are supported,
     * 8.2 and 6.4 only get security fixes, 8.1 and 5.4 have ended.
     *
     * @return array{cycles: array<string, array{eol: string, support: string}>}
     */
    public static function cycles(): array
    {
        return ['cycles' => [
            '8.4' => ['eol' => '2028-12-31', 'support' => '2026-12-31'],
            '8.2' => ['eol' => '2026-12-31', 'support' => '2024-12-31'],
            '8.1' => ['eol' => '2025-12-31', 'support' => '2023-11-25'],
            '7.4' => ['eol' => '2029-11-30', 'support' => '2028-11-30'],
            '6.4' => ['eol' => '2027-11-30', 'support' => '2025-11-30'],
            '5.4' => ['eol' => '2025-02-28', 'support' => '2024-11-30'],
        ]];
    }
}
