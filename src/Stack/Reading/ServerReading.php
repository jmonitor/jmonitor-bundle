<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final readonly class ServerReading
{
    /**
     * @param ?float $loadPercent       the load over one minute, against the CPU cores
     * @param bool   $container         a cgroup limits the memory or the CPU
     * @param ?float $containerCpuQuota in CPUs
     */
    private function __construct(
        public ?float $memoryPercent,
        public ?int $memoryUsed,
        public ?int $memoryTotal,
        public ?float $diskPercent,
        public ?int $diskUsed,
        public ?int $diskTotal,
        public ?float $loadPercent,
        public ?float $load1,
        public ?float $load5,
        public ?float $load15,
        public ?int $cores,
        public bool $container,
        public ?int $containerMemoryLimit,
        public ?float $containerCpuQuota,
        public ?string $os,
        public ?string $hostname,
        public ?int $uptime,
        public ?string $timezone,
    ) {}

    public static function of(ComponentSnapshot $snapshot): self
    {
        $data = $snapshot->data('system');

        $memoryTotal = Values::int($data['ram']['total'] ?? null);
        $memoryAvailable = Values::int($data['ram']['available'] ?? null);
        $memoryUsed = $memoryTotal !== null && $memoryAvailable !== null ? $memoryTotal - $memoryAvailable : null;
        $diskTotal = Values::int($data['disk']['total'] ?? null);
        $diskFree = Values::int($data['disk']['free'] ?? null);
        $diskUsed = $diskTotal !== null && $diskFree !== null ? $diskTotal - $diskFree : null;
        $memoryLimit = Values::int($data['cgroup']['memory_limit'] ?? null);
        $cpuQuota = Values::float($data['cgroup']['cpu_quota'] ?? null);

        return new self(
            Values::percent($memoryUsed, $memoryTotal),
            $memoryUsed,
            $memoryTotal,
            Values::percent($diskUsed, $diskTotal),
            $diskUsed,
            $diskTotal,
            Values::float($data['cpu']['load'] ?? null),
            Values::float($data['cpu']['load1'] ?? null),
            Values::float($data['cpu']['load5'] ?? null),
            Values::float($data['cpu']['load15'] ?? null),
            Values::int($data['cpu']['cores'] ?? null),
            $memoryLimit !== null || $cpuQuota !== null,
            $memoryLimit,
            $cpuQuota,
            Values::string($data['os']['pretty_name'] ?? null),
            Values::string($data['hostname'] ?? null),
            Values::int($data['os']['uptime'] ?? null),
            Values::string($data['timezone'] ?? null),
        );
    }
}
