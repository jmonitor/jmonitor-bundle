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
    private const OPCACHE_HITS = 'opcache.status.opcache_statistics.hits';
    private const OPCACHE_MISSES = 'opcache.status.opcache_statistics.misses';

    /**
     * @param bool   $opcacheAbsent                 the extension is not loaded, or its status cannot be read
     * @param ?bool  $opcacheRunning                null when nothing tells
     * @param ?float $opcacheMemoryPercent          wasted memory counts as taken: it is only freed by a restart
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
        public bool $opcacheAbsent,
        public ?bool $opcacheRunning,
        public bool $opcacheEnabled,
        public ?int $opcacheUsed,
        public ?int $opcacheWasted,
        public ?int $opcacheTotal,
        public ?float $opcacheMemoryPercent,
        public bool $opcacheFull,
        public Rate $opcacheHitRate,
        public Rate $opcacheRecentHitRate,
        public Rate $opcacheCumulativeHitRate,
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

        $reportedOpcache = $data['opcache'] ?? null;
        $opcache = is_array($reportedOpcache) ? $reportedOpcache : [];
        // The directive can be on while the accelerator is off at runtime: the status comes first.
        $opcacheRunning = $opcache === [] ? null : Values::bool($opcache['status']['opcache_enabled'] ?? $opcache['config']['directives']['opcache.enable'] ?? null);
        $opcacheMemory = $opcache['status']['memory_usage'] ?? [];
        $opcacheStatistics = $opcache['status']['opcache_statistics'] ?? [];
        $opcacheUsed = Values::int($opcacheMemory['used_memory'] ?? null);
        $opcacheFree = Values::int($opcacheMemory['free_memory'] ?? null);
        $opcacheWasted = Values::int($opcacheMemory['wasted_memory'] ?? null);
        $opcacheTotal = $opcacheUsed !== null && $opcacheFree !== null && $opcacheWasted !== null ? Values::int($opcacheUsed + $opcacheFree + $opcacheWasted) : null;
        $opcacheTaken = $opcacheUsed !== null && $opcacheWasted !== null ? Values::int($opcacheUsed + $opcacheWasted) : null;
        $opcacheHits = Values::int($opcacheStatistics['hits'] ?? null);
        $opcacheMisses = Values::int($opcacheStatistics['misses'] ?? null);

        $apcu = is_array($data['apcu'] ?? null) ? $data['apcu'] : [];
        $apcuCache = $apcu['cache_info'] ?? [];
        $apcuSegments = Values::int($apcu['sma_info']['num_seg'] ?? null);
        $apcuSegmentSize = Values::int($apcu['sma_info']['seg_size'] ?? null);
        $apcuAvailable = Values::int($apcu['sma_info']['avail_mem'] ?? null);
        $apcuTotal = $apcuSegments !== null && $apcuSegmentSize !== null ? Values::int($apcuSegments * $apcuSegmentSize) : null;
        $apcuUsed = $apcuTotal !== null && $apcuAvailable !== null ? Values::int($apcuTotal - $apcuAvailable) : null;

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
            $reportedOpcache === false || $reportedOpcache === [],
            $opcacheRunning,
            $opcacheRunning === true,
            $opcacheUsed,
            $opcacheWasted,
            $opcacheTotal,
            Values::percent($opcacheTaken, $opcacheTotal),
            Values::bool($opcache['status']['cache_full'] ?? null) === true,
            Ratios::share($delta, self::OPCACHE_HITS, self::OPCACHE_MISSES, $opcacheHits, $opcacheMisses),
            Ratios::share($delta, self::OPCACHE_HITS, self::OPCACHE_MISSES, null, null),
            Ratios::share(null, self::OPCACHE_HITS, self::OPCACHE_MISSES, $opcacheHits, $opcacheMisses),
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
            $fpmActive !== null && $fpmIdle !== null ? Values::int($fpmActive + $fpmIdle) : null,
            Values::int($fpm['listen-queue'] ?? null),
            Values::int($fpm['max-children-reached'] ?? null),
            $fpmRecently === null ? null : (float) $fpmRecently,
            Values::int($fpm['slow-requests'] ?? null),
            Ratios::perSecond($delta, 'fpm.accepted-conn', 'req/s', Values::int($fpm['accepted-conn'] ?? null)),
        );
    }

    private static function displayErrors(mixed $value): ?bool
    {
        if (is_string($value) && in_array(strtolower($value), ['stderr', 'stdout'], true)) {
            return true;
        }

        return Values::bool($value);
    }
}
