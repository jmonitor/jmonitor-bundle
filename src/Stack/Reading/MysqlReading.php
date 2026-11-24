<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Metric\EndOfLife;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\Ratios;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final readonly class MysqlReading
{
    /** a variation with fewer events tells nothing about the ratio */
    private const BUFFER_POOL_REQUESTS = 1_000;
    private const TEMPORARY_TABLES = 100;

    private const SINCE_START = 'since the server started';

    private const NOT_SETTINGS = ['version', 'version_comment', 'timestamp'];

    /**
     * @param ?string               $version            the number, without the suffix of the build
     * @param ?SupportStatus        $support             null for the forks with their own release cycles, such as Percona Server, TiDB or Aurora
     * @param Rate                  $bufferPoolHitRate   the variation between the two samples when it holds BUFFER_POOL_REQUESTS, else since the server started
     * @param Rate                  $diskTemporaryTables the variation between the two samples when it holds TEMPORARY_TABLES, else since the server started
     * @param ?int                  $tableLocks          taken between the last two samples
     * @param bool                  $sizesCached         MySQL 8 reads the sizes from statistics it renews once a day
     * @param list<MysqlSlowQuery>  $slowQueries
     * @param array<string, string> $settings            the server variables read, by name
     */
    private function __construct(
        public ?string $product,
        public ?string $version,
        public ?string $versionComment,
        public ?SupportStatus $support,
        public ?int $uptime,
        public ?string $timeZone,
        public ?string $systemTimeZone,
        public ?int $connections,
        public ?int $maxConnections,
        public ?float $connectionsPercent,
        public ?int $maxUsedConnections,
        public ?int $running,
        public Rate $queriesPerSecond,
        public Rate $bufferPoolHitRate,
        public ?int $bufferPoolSize,
        public ?float $bufferPoolUsedPercent,
        public Rate $innodbReadsPerSecond,
        public Rate $innodbWritesPerSecond,
        public Rate $diskTemporaryTables,
        public Rate $tableLockWaits,
        public ?int $tableLocks,
        public ?int $dataLength,
        public ?int $indexLength,
        public bool $sizesCached,
        public array $slowQueries,
        public ?bool $slowQueryLog,
        public ?float $longQueryTime,
        public ?int $slowQueriesSinceStart,
        public array $settings,
    ) {}

    public static function of(ComponentSnapshot $snapshot, \DateTimeImmutable $today): self
    {
        $status = $snapshot->data('mysql');
        $variables = $snapshot->data('mysql.variables');
        $sizes = $snapshot->data('mysql.sizes');
        $delta = $snapshot->delta('mysql');

        $fullVersion = Values::string($variables['version'] ?? null);
        $versionComment = Values::string($variables['version_comment'] ?? null);
        $version = $fullVersion !== null && preg_match('/^\d+\.\d+(?:\.\d+)?/', $fullVersion, $matches) === 1 ? $matches[0] : null;
        $product = match (true) {
            $fullVersion === null => null,
            str_contains(strtolower($fullVersion), 'mariadb') => 'MariaDB',
            default => 'MySQL',
        };
        $fork = preg_match('/percona|tidb|aurora/i', $fullVersion . ' ' . $versionComment) === 1;

        $connections = Values::int($status['Threads_connected'] ?? null);
        $maxConnections = Values::int($variables['max_connections'] ?? null);
        $pagesTotal = Values::int($status['Innodb_buffer_pool_pages_total'] ?? null);
        $pagesFree = Values::int($status['Innodb_buffer_pool_pages_free'] ?? null);

        $readRequests = Values::int($status['Innodb_buffer_pool_read_requests'] ?? null);
        $diskReads = Values::int($status['Innodb_buffer_pool_reads'] ?? null);
        $temporary = Values::int($status['Created_tmp_tables'] ?? null);
        $diskTemporary = Values::int($status['Created_tmp_disk_tables'] ?? null);
        $locksWaited = Values::int($status['Table_locks_waited'] ?? null);
        $locksImmediate = Values::int($status['Table_locks_immediate'] ?? null);
        $recentHitRate = Ratios::complement($delta, 'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests', null, null);
        $recentDiskTemporary = Ratios::percent($delta, 'Created_tmp_disk_tables', 'Created_tmp_tables', null, null);
        $waitedLately = $delta?->getValue('Table_locks_waited');
        $immediateLately = $delta?->getValue('Table_locks_immediate');

        return new self(
            $product,
            $version,
            $versionComment,
            $fork ? null : EndOfLife::status($snapshot->data($product === 'MariaDB' ? 'eol.mariadb' : 'eol.mysql'), $version, $today),
            Values::int($status['Uptime'] ?? null),
            Values::string($variables['time_zone'] ?? null),
            Values::string($variables['system_time_zone'] ?? null),
            $connections,
            $maxConnections,
            Values::percent($connections, $maxConnections),
            Values::int($status['Max_used_connections'] ?? null),
            Values::int($status['Threads_running'] ?? null),
            Ratios::perSecond($delta, 'Questions', 'q/s', Values::int($status['Questions'] ?? null)),
            $recentHitRate->total !== null && $recentHitRate->total >= self::BUFFER_POOL_REQUESTS
                ? $recentHitRate
                : Ratios::complement(null, 'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests', $diskReads, $readRequests, self::SINCE_START),
            Values::int($variables['innodb_buffer_pool_size'] ?? null),
            $pagesTotal === null || $pagesFree === null ? null : Values::percent($pagesTotal - $pagesFree, $pagesTotal),
            Ratios::perSecond($delta, 'Innodb_data_reads', 'reads/s', Values::int($status['Innodb_data_reads'] ?? null)),
            Ratios::perSecond($delta, 'Innodb_data_writes', 'writes/s', Values::int($status['Innodb_data_writes'] ?? null)),
            $recentDiskTemporary->total !== null && $recentDiskTemporary->total >= self::TEMPORARY_TABLES
                ? $recentDiskTemporary
                : Ratios::percent(null, 'Created_tmp_disk_tables', 'Created_tmp_tables', $diskTemporary, $temporary, self::SINCE_START),
            Ratios::share($delta, 'Table_locks_waited', 'Table_locks_immediate', $locksWaited, $locksImmediate, self::SINCE_START),
            $waitedLately === null || $immediateLately === null ? null : Values::int($waitedLately + $immediateLately),
            Values::int($sizes['data_length'] ?? null),
            Values::int($sizes['index_length'] ?? null),
            $product === 'MySQL' && $version !== null && (int) $version >= 8,
            self::slowQueries($snapshot->data('mysql.slow_queries')['queries'] ?? null),
            Values::bool($variables['slow_query_log'] ?? null),
            Values::float($variables['long_query_time'] ?? null),
            Values::int($status['Slow_queries'] ?? null),
            self::settings($variables),
        );
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

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, string>
     */
    private static function settings(array $variables): array
    {
        $settings = [];

        foreach ($variables as $name => $value) {
            $value = Values::string($value);

            if ($value !== null && !in_array($name, self::NOT_SETTINGS, true)) {
                $settings[$name] = $value;
            }
        }

        return $settings;
    }
}
