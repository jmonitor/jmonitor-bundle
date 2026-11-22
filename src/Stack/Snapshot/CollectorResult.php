<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

final readonly class CollectorResult
{
    /**
     * @param array<string, mixed> $data
     * @param ?string              $error with Stale, why the value could not be renewed
     * @param ?int                 $expiresAt when the value is read again, or when a failure is retried
     */
    public function __construct(
        public string $name,
        public array $data,
        public ResultState $state,
        public ?string $error = null,
        public ?int $collectedAt = null,
        public ?Delta $delta = null,
        public ?int $expiresAt = null,
    ) {}

    public function ok(): bool
    {
        return $this->state === ResultState::Fresh || $this->state === ResultState::Stale;
    }
}
