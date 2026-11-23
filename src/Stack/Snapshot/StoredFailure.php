<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;

/**
 * A failure kept so that the collector is not retried before $until.
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
        public FailureKind $kind = FailureKind::Other,
    ) {}

    public static function fromCache(mixed $payload): ?self
    {
        if (!is_array($payload) || !is_string($payload['error'] ?? null) || !is_int($payload['until'] ?? null)) {
            return null;
        }

        if (!array_key_exists('forgotten', $payload) || ($payload['forgotten'] !== null && !is_string($payload['forgotten']))) {
            return null;
        }

        $kind = is_string($payload['kind'] ?? null) ? FailureKind::tryFrom($payload['kind']) : null;

        return new self($payload['error'], $payload['until'], $payload['forgotten'], $kind ?? FailureKind::Other);
    }

    /**
     * @return array{error: string, until: int, forgotten: ?string, kind: string}
     */
    public function toCache(): array
    {
        return ['error' => $this->error, 'until' => $this->until, 'forgotten' => $this->forgotten, 'kind' => $this->kind->value];
    }

    public function isKept(int $now): bool
    {
        return $now < $this->until;
    }
}
