<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\ResetInterface;
use Jmonitor\Collector\System\SystemCollector;

final class ServerCollector implements CollectorInterface, ResetInterface
{
    private readonly ProcSystemAdapter $adapter;
    private readonly SystemCollector $system;

    public function __construct(?ProcSystemAdapter $adapter = null)
    {
        $this->adapter = $adapter ?? new ProcSystemAdapter();
        $this->system = new SystemCollector($this->adapter);
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return $this->system->collect() + [
            'cgroup' => [
                'memory_limit' => $this->adapter->memoryLimit(),
                'cpu_quota' => $this->adapter->cpuQuota(),
            ],
        ];
    }

    public function reset(): void
    {
        $this->system->reset();
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'system';
    }
}
