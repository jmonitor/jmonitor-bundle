<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;

/**
 * @internal
 */
final readonly class StoredValue
{
    /**
     * @param array<string, mixed> $data
     * @param ?string              $forgotten the forget marker in force when it was collected
     */
    public function __construct(
        public array $data,
        public int $collectedAt,
        public ?Delta $delta,
        public ?string $forgotten,
    ) {}

    /**
     * Another version of the bundle may have written something else: it counts as absent.
     */
    public static function fromCache(mixed $payload): ?self
    {
        if (!is_array($payload) || !is_array($payload['data'] ?? null) || !is_int($payload['collected_at'] ?? null)) {
            return null;
        }

        if (!array_key_exists('forgotten', $payload) || ($payload['forgotten'] !== null && !is_string($payload['forgotten']))) {
            return null;
        }

        $delta = $payload['delta'] ?? null;

        if ($delta !== null && !(is_array($delta) && is_array($delta['previous'] ?? null) && is_array($delta['current'] ?? null) && is_int($delta['elapsed'] ?? null))) {
            return null;
        }

        return new self($payload['data'], $payload['collected_at'], $delta === null ? null : Delta::fromArray($delta), $payload['forgotten']);
    }

    /**
     * @return array{data: array<string, mixed>, collected_at: int, delta: ?array{previous: array<string, mixed>, current: array<string, mixed>, elapsed: int}, forgotten: ?string}
     */
    public function toCache(): array
    {
        return ['data' => $this->data, 'collected_at' => $this->collectedAt, 'delta' => $this->delta?->toArray(), 'forgotten' => $this->forgotten];
    }

    public function isFresh(Lifetime $lifetime, int $now): bool
    {
        return $now - $this->collectedAt < $lifetime->seconds();
    }

    public function isShown(Lifetime $lifetime, int $now): bool
    {
        $shownFor = $lifetime->shownFor();

        return $shownFor === null || $now - $this->collectedAt < $shownFor;
    }
}
