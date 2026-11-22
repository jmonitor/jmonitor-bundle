<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\ResetInterface;
use Symfony\Component\Clock\MockClock;

final class FakeCollector implements CollectorInterface, BootableCollectorInterface, ResetInterface
{
    public int $boots = 0;
    public int $collects = 0;
    public int $resets = 0;

    /**
     * @param array<string, mixed>   $data
     * @param ?\ArrayObject<int, string> $journal receives the name of the collector at each collect()
     */
    public function __construct(
        private readonly string $name,
        private array $data = [],
        private readonly ?\Throwable $bootFailure = null,
        private readonly ?\Throwable $collectFailure = null,
        private readonly ?MockClock $clock = null,
        private readonly float $duration = 0.0,
        private readonly ?\ArrayObject $journal = null,
    ) {}

    public function boot(): void
    {
        $this->boots++;

        if ($this->bootFailure !== null) {
            throw $this->bootFailure;
        }
    }

    public function collect(): array
    {
        $this->collects++;
        $this->journal?->append($this->name);
        $this->clock?->sleep($this->duration);

        if ($this->collectFailure !== null) {
            throw $this->collectFailure;
        }

        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function reset(): void
    {
        $this->resets++;
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
