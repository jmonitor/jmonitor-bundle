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
    /**
     * @param ?string $forgotten the forget marker in force when it failed
     */
    public function __construct(
        public string $error,
        public int $until,
        public ?string $forgotten,
    ) {}

    public static function fromCache(mixed $payload): ?self
    {
        if (!is_array($payload) || !is_string($payload['error'] ?? null) || !is_int($payload['until'] ?? null)) {
            return null;
        }

        if (!array_key_exists('forgotten', $payload) || ($payload['forgotten'] !== null && !is_string($payload['forgotten']))) {
            return null;
        }

        return new self($payload['error'], $payload['until'], $payload['forgotten']);
    }

    /**
     * @return array{error: string, until: int, forgotten: ?string}
     */
    public function toCache(): array
    {
        return ['error' => $this->error, 'until' => $this->until, 'forgotten' => $this->forgotten];
    }

    public function isKept(int $now): bool
    {
        return $now < $this->until;
    }
}
