<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

/**
 * A failure kept so that the collector, or the building of its set, is not retried before $until.
 *
 * @internal
 */
final readonly class StoredFailure
{
    public function __construct(
        public string $error,
        public int $failedAt,
        public int $until,
    ) {}

    public static function fromCache(mixed $payload): ?self
    {
        if (!is_array($payload) || !is_string($payload['error'] ?? null) || !is_int($payload['failed_at'] ?? null) || !is_int($payload['until'] ?? null)) {
            return null;
        }

        return new self($payload['error'], $payload['failed_at'], $payload['until']);
    }

    /**
     * @return array{error: string, failed_at: int, until: int}
     */
    public function toCache(): array
    {
        return ['error' => $this->error, 'failed_at' => $this->failedAt, 'until' => $this->until];
    }

    public function isKept(int $now): bool
    {
        return $now < $this->until;
    }
}
