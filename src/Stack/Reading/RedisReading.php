<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Metric\EndOfLife;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\Ratios;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;

final readonly class RedisReading
{
    public const SINCE_START = 'since the server started';

    private const VALKEY = 'Valkey';

    /** a variation with fewer lookups tells nothing about the ratio */
    private const LOOKUPS = 1_000;

    /**
     * @param ?string                                                $version       for Valkey, its own, not the Redis version it tells for compatibility
     * @param ?int                                                   $maxMemory     null when maxmemory is 0, which sets no limit
     * @param bool                                                   $evicts        a limit is set, and its policy evicts keys to stay under it
     * @param bool                                                   $refusesWrites a limit is set, and its policy has nothing to evict: noeviction, or volatile-* while no key has an expiry
     * @param Rate                                                   $hitRate       between the last two samples when they hold LOOKUPS, else since the server started
     * @param ?int                                                   $lookups       hits and misses between the last two samples
     * @param ?int                                                   $evictedLately between the last two samples
     * @param ?int                                                   $lastSaveAt    Unix timestamp of the last RDB snapshot that succeeded, or of the start of a server that never saved
     * @param list<array{name: string, keys: int, expires: ?int}> $databases
     */
    private function __construct(
        public ?string $product,
        public ?string $version,
        public ?SupportStatus $support,
        public ?int $uptime,
        public ?string $mode,
        public ?string $role,
        public ?int $usedMemory,
        public ?int $maxMemory,
        public ?float $memoryPercent,
        public ?int $peakMemory,
        public ?string $policy,
        public bool $evicts,
        public bool $refusesWrites,
        public ?int $clients,
        public Rate $commandsPerSecond,
        public Rate $hitRate,
        public ?int $lookups,
        public ?int $evictedLately,
        public ?int $evictedKeys,
        public ?int $rejectedLately,
        public ?int $rejectedConnections,
        public ?string $rdbStatus,
        public bool $rdbInProgress,
        public ?int $lastSaveAt,
        public ?int $unsavedChanges,
        public bool $aofEnabled,
        public ?string $aofStatus,
        public bool $aofInProgress,
        public array $databases,
    ) {}

    public static function of(ComponentSnapshot $snapshot, \DateTimeImmutable $today): self
    {
        $data = $snapshot->data('redis');
        $delta = $snapshot->delta('redis');
        $server = self::array($data['server'] ?? null);
        $memory = self::array($data['memory'] ?? null);
        $persistence = self::array($data['persistence'] ?? null);
        $stats = self::array($data['stats'] ?? null);

        $redisVersion = Values::string($server['version'] ?? null);
        $valkeyVersion = is_string($server['valkey_version'] ?? null) ? Values::string($server['valkey_version']) : null;
        $product = match (true) {
            ($server['name'] ?? null) === 'valkey' || $valkeyVersion !== null => self::VALKEY,
            $redisVersion !== null => 'Redis',
            default => null,
        };
        $version = $product === self::VALKEY ? $valkeyVersion : $redisVersion;

        $used = Values::int($memory['used'] ?? null);
        $limit = Values::int($memory['max_memory'] ?? null);
        $maxMemory = $limit !== null && $limit > 0 ? $limit : null;
        $policy = is_string($memory['max_memory_policy'] ?? null) ? Values::string($memory['max_memory_policy']) : null;

        $databases = self::databases($data['databases'] ?? null);

        $hits = $delta?->getValue('stats.keyspace_hits');
        $misses = $delta?->getValue('stats.keyspace_misses');
        $recentHitRate = Ratios::share($delta, 'stats.keyspace_hits', 'stats.keyspace_misses', null, null);

        return new self(
            $product,
            $version,
            $product === null ? null : EndOfLife::status($snapshot->data($product === self::VALKEY ? 'eol.valkey' : 'eol.redis'), $version, $today),
            Values::int($server['uptime'] ?? null),
            self::text($server['mode'] ?? null),
            self::text(self::array($data['replication'] ?? null)['role'] ?? null),
            $used,
            $maxMemory,
            Values::percent($used, $maxMemory),
            Values::int($memory['used_peak'] ?? null),
            $policy,
            $maxMemory !== null && $policy !== null && $policy !== 'noeviction',
            $maxMemory !== null && match (true) {
                $policy === 'noeviction' => true,
                $policy !== null && str_starts_with($policy, 'volatile-') => self::noKeyExpires($databases),
                default => false,
            },
            Values::int(self::array($data['clients'] ?? null)['connected'] ?? null),
            Ratios::perSecond($delta, 'stats.total_commands_processed', 'cmd/s', Values::int($stats['total_commands_processed'] ?? null)),
            $recentHitRate->total !== null && $recentHitRate->total >= self::LOOKUPS
                ? $recentHitRate
                : Ratios::share(null, 'stats.keyspace_hits', 'stats.keyspace_misses', Values::int($stats['keyspace_hits'] ?? null), Values::int($stats['keyspace_misses'] ?? null), self::SINCE_START),
            $hits === null || $misses === null ? null : Values::int($hits + $misses),
            self::variation($delta, 'stats.evicted_keys'),
            Values::int($stats['evicted_keys'] ?? null),
            self::variation($delta, 'stats.rejected_connections'),
            Values::int($stats['rejected_connections'] ?? null),
            self::text($persistence['rdb_last_bgsave_status'] ?? null),
            Values::bool($persistence['rdb_bgsave_in_progress'] ?? null) === true,
            Values::int($persistence['rdb_last_save_time'] ?? null),
            Values::int($persistence['rdb_changes_since_last_save'] ?? null),
            Values::bool($persistence['aof_enabled'] ?? null) === true,
            self::text($persistence['aof_last_bgrewrite_status'] ?? null),
            Values::bool($persistence['aof_rewrite_in_progress'] ?? null) === true,
            $databases,
        );
    }

    /**
     * @param list<array{name: string, keys: int, expires: ?int}> $databases
     */
    private static function noKeyExpires(array $databases): bool
    {
        $keys = 0;

        foreach ($databases as $database) {
            if ($database['keys'] > 0 && $database['expires'] !== 0) {
                return false;
            }

            $keys += $database['keys'];
        }

        return $keys > 0;
    }

    private static function variation(?Delta $delta, string $key): ?int
    {
        $value = $delta?->getValue($key);

        return $value === null ? null : Values::int($value);
    }

    /**
     * @return array<mixed>
     */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? Values::string($value) : null;
    }

    /**
     * @return list<array{name: string, keys: int, expires: ?int}>
     */
    private static function databases(mixed $databases): array
    {
        $kept = [];

        foreach (is_array($databases) ? $databases : [] as $name => $database) {
            $keys = is_array($database) ? Values::int($database['keys'] ?? null) : null;

            if ($keys !== null) {
                $kept[] = ['name' => (string) $name, 'keys' => $keys, 'expires' => Values::int($database['expires'] ?? null)];
            }
        }

        return $kept;
    }
}
