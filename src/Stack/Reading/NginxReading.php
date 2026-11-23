<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Metric\EndOfLife;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\Ratios;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final readonly class NginxReading
{
    /**
     * @param ?int    $maxConnections  worker_processes × worker_connections, null when either is unknown
     * @param ?int    $refused         connections accepted and not handled, since Nginx started
     * @param ?int    $refusedRecently between the last two samples
     * @param ?string $workerProcesses "auto" followed by the CPU count it stands for, when known
     */
    private function __construct(
        public ?string $version,
        public ?SupportStatus $support,
        public ?int $active,
        public ?int $reading,
        public ?int $writing,
        public ?int $waiting,
        public ?int $maxConnections,
        public ?float $connectionsPercent,
        public Rate $requestsPerSecond,
        public Rate $requestsPerConnection,
        public ?int $refused,
        public ?int $refusedRecently,
        public ?string $workerProcesses,
        public ?int $workerConnections,
        public ?int $keepaliveTimeout,
        public ?string $gzip,
        public ?string $serverTokens,
        public ?string $sslProtocols,
        public ?string $configPath,
    ) {}

    public static function of(ComponentSnapshot $snapshot, \DateTimeImmutable $today): self
    {
        $data = $snapshot->data('nginx.config');
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $status = $snapshot->data('nginx')['status'] ?? null;
        $status = is_array($status) ? $status : [];
        $delta = $snapshot->delta('nginx');

        $version = Values::string($data['version'] ?? null);
        $processes = Values::string($config['worker_processes'] ?? null);
        $cpus = Values::int($data['cpu_count'] ?? null);
        $processCount = $processes === 'auto' ? $cpus : Values::int($processes);
        $workerConnections = Values::int($config['worker_connections'] ?? null);
        $maxConnections = $processCount === null || $workerConnections === null ? null : Values::int($processCount * $workerConnections);

        $active = Values::int($status['active'] ?? null);
        $accepts = Values::int($status['accepts'] ?? null);
        $handled = Values::int($status['handled'] ?? null);
        $requests = Values::int($status['requests'] ?? null);
        $acceptedLately = $delta?->getValue('status.accepts');
        $handledLately = $delta?->getValue('status.handled');

        return new self(
            $version,
            EndOfLife::status($snapshot->data('eol.nginx'), $version, $today),
            $active,
            Values::int($status['reading'] ?? null),
            Values::int($status['writing'] ?? null),
            Values::int($status['waiting'] ?? null),
            $maxConnections,
            Values::percent($active, $maxConnections),
            Ratios::perSecond($delta, 'status.requests', 'req/s', $requests),
            Ratios::average($delta, 'status.requests', 'status.handled', $requests, $handled, ''),
            $accepts === null || $handled === null ? null : Values::int(max(0, $accepts - $handled)),
            $acceptedLately === null || $handledLately === null ? null : Values::int(max(0, $acceptedLately - $handledLately)),
            $processes === 'auto' && $cpus !== null ? sprintf('auto (%d %s)', $cpus, $cpus === 1 ? 'CPU' : 'CPUs') : $processes,
            $workerConnections,
            Values::int($config['keepalive_timeout'] ?? null),
            Values::string($config['gzip'] ?? null),
            Values::string($config['server_tokens'] ?? null),
            Values::string($config['ssl_protocols'] ?? null),
            Values::string($config['config_path'] ?? null),
        );
    }
}
