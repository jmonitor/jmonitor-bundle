<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Metric\EndOfLife;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\Ratios;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final readonly class PhpReading
{
    /**
     * @param bool   $opcacheReported               the payload has an opcache entry: without one, whether OPcache runs is unknown
     * @param ?int   $maxExecutionTime              in seconds, 0 for no limit
     * @param ?int   $fpmMaxChildrenReached         since the pool started
     * @param ?float $fpmMaxChildrenReachedRecently between the last two samples
     */
    private function __construct(
        public ?string $version,
        public ?SupportStatus $support,
        public ?string $sapi,
        public ?int $memoryLimit,
        public bool $memoryUnlimited,
        public ?int $maxExecutionTime,
        public ?bool $displayErrors,
        public ?int $realpathCacheSize,
        public bool $opcacheReported,
        public bool $opcacheEnabled,
        public ?int $opcacheUsed,
        public ?int $opcacheTotal,
        public ?float $opcacheMemoryPercent,
        public bool $opcacheFull,
        public Rate $opcacheHitRate,
        public bool $apcuEnabled,
        public ?int $apcuUsed,
        public ?int $apcuTotal,
        public ?float $apcuMemoryPercent,
        public Rate $apcuHitRate,
        public bool $fpm,
        public ?string $fpmPool,
        public ?string $fpmProcessManager,
        public ?int $fpmActive,
        public ?int $fpmIdle,
        public ?int $fpmTotal,
        public ?int $fpmQueue,
        public ?int $fpmMaxChildrenReached,
        public ?float $fpmMaxChildrenReachedRecently,
        public ?int $fpmSlowRequests,
        public Rate $fpmRequestsPerSecond,
    ) {}

    public static function of(ComponentSnapshot $snapshot, \DateTimeImmutable $today): self
    {
        $data = $snapshot->data('php');
        $delta = $snapshot->delta('php');
        $version = Values::string($data['version'] ?? null);
        $memoryLimit = Values::iniBytes($data['memory_limit'] ?? null);
        $realpathCacheSize = Values::iniBytes($data['realpath_cache_size'] ?? null);

        $opcache = is_array($data['opcache'] ?? null) ? $data['opcache'] : [];
        $opcacheMemory = $opcache['status']['memory_usage'] ?? [];
        $opcacheStatistics = $opcache['status']['opcache_statistics'] ?? [];
        $opcacheUsed = Values::int($opcacheMemory['used_memory'] ?? null);
        $opcacheFree = Values::int($opcacheMemory['free_memory'] ?? null);
        $opcacheWasted = Values::int($opcacheMemory['wasted_memory'] ?? null);
        $opcacheTotal = $opcacheUsed !== null && $opcacheFree !== null && $opcacheWasted !== null ? $opcacheUsed + $opcacheFree + $opcacheWasted : null;

        $apcu = is_array($data['apcu'] ?? null) ? $data['apcu'] : [];
        $apcuCache = $apcu['cache_info'] ?? [];
        $apcuSegments = Values::int($apcu['sma_info']['num_seg'] ?? null);
        $apcuSegmentSize = Values::int($apcu['sma_info']['seg_size'] ?? null);
        $apcuAvailable = Values::int($apcu['sma_info']['avail_mem'] ?? null);
        $apcuTotal = $apcuSegments !== null && $apcuSegmentSize !== null ? $apcuSegments * $apcuSegmentSize : null;
        $apcuUsed = $apcuTotal !== null && $apcuAvailable !== null ? $apcuTotal - $apcuAvailable : null;

        $fpm = is_array($data['fpm'] ?? null) ? $data['fpm'] : [];
        $fpmActive = Values::int($fpm['active-processes'] ?? null);
        $fpmIdle = Values::int($fpm['idle-processes'] ?? null);
        $fpmRecently = $delta?->getValue('fpm.max-children-reached');

        return new self(
            $version,
            EndOfLife::status($snapshot->data('eol.php'), $version, $today),
            Values::string($data['sapi_name'] ?? null),
            is_int($memoryLimit) ? $memoryLimit : null,
            $memoryLimit === false,
            Values::int($data['max_execution_time'] ?? null),
            self::displayErrors($data['display_errors'] ?? null),
            is_int($realpathCacheSize) ? $realpathCacheSize : null,
            array_key_exists('opcache', $data),
            self::opcacheRuns($opcache),
            $opcacheUsed,
            $opcacheTotal,
            Values::percent($opcacheUsed, $opcacheTotal),
            Values::bool($opcache['status']['cache_full'] ?? null) === true,
            Ratios::share(
                $delta,
                'opcache.status.opcache_statistics.hits',
                'opcache.status.opcache_statistics.misses',
                Values::int($opcacheStatistics['hits'] ?? null),
                Values::int($opcacheStatistics['misses'] ?? null),
            ),
            Values::bool($apcu['config']['apc.enabled'] ?? null) === true,
            $apcuUsed,
            $apcuTotal,
            Values::percent($apcuUsed, $apcuTotal),
            Ratios::share(
                $delta,
                'apcu.cache_info.num_hits',
                'apcu.cache_info.num_misses',
                Values::int($apcuCache['num_hits'] ?? null),
                Values::int($apcuCache['num_misses'] ?? null),
            ),
            $fpm !== [],
            Values::string($fpm['pool'] ?? null),
            Values::string($fpm['process-manager'] ?? null),
            $fpmActive,
            $fpmIdle,
            $fpmActive !== null && $fpmIdle !== null ? $fpmActive + $fpmIdle : null,
            Values::int($fpm['listen-queue'] ?? null),
            Values::int($fpm['max-children-reached'] ?? null),
            $fpmRecently === null ? null : (float) $fpmRecently,
            Values::int($fpm['slow-requests'] ?? null),
            Ratios::perSecond($delta, 'fpm.accepted-conn', 'req/s', Values::int($fpm['accepted-conn'] ?? null)),
        );
    }

    /**
     * The directive can be on while the accelerator is off at runtime; the status tells.
     *
     * @param array<mixed> $opcache
     */
    private static function opcacheRuns(array $opcache): bool
    {
        return Values::bool($opcache['status']['opcache_enabled'] ?? $opcache['config']['directives']['opcache.enable'] ?? null) === true;
    }

    private static function displayErrors(mixed $value): ?bool
    {
        if (is_string($value) && in_array(strtolower($value), ['stderr', 'stdout'], true)) {
            return true;
        }

        return Values::bool($value);
    }
}
