<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Component;

final readonly class ComponentSnapshot
{
    /**
     * @param array<string, CollectorResult> $results keyed by collector name
     * @param ?string                        $error   why the whole instance is unavailable, if it is
     * @param ?string                        $node    the web server that answered, for a local component
     */
    public function __construct(
        public Component $component,
        public array $results,
        public ?string $error = null,
        public ?string $node = null,
    ) {}

    public function result(string $name): ?CollectorResult
    {
        return $this->results[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(string $name): array
    {
        return $this->result($name)->data ?? [];
    }

    public function has(string $name): bool
    {
        return $this->result($name)?->ok() ?? false;
    }

    /**
     * Whether this collector failed because the server of a remote component cannot be reached, which the main
     * collector of the component already tells.
     */
    public function failedWithItsServer(string $name): bool
    {
        $result = $this->result($name);

        return $name !== $this->component->value
            && !$this->component->isNodeLocal()
            && $result?->state === ResultState::Failed
            && $result->failure === FailureKind::Unreachable
            && $this->result($this->component->value)?->failure === FailureKind::Unreachable;
    }

    public function delta(string $name): ?Delta
    {
        return $this->result($name)?->delta;
    }

    /**
     * The oldest read and the earliest next read of the given collectors, among those with a value.
     */
    public function freshness(int $now, string ...$names): ?Freshness
    {
        $results = array_filter(
            array_map($this->result(...), $names),
            static fn(?CollectorResult $result): bool => $result !== null && $result->ok() && $result->collectedAt !== null && $result->expiresAt !== null,
        );

        if ($results === []) {
            return null;
        }

        $readAt = min(array_map(static fn(CollectorResult $result): int => (int) $result->collectedAt, $results));
        $expiresAt = min(array_map(static fn(CollectorResult $result): int => (int) $result->expiresAt, $results));
        $lifetime = min(array_map(static fn(CollectorResult $result): int => (int) $result->expiresAt - (int) $result->collectedAt, $results));

        return new Freshness(min($readAt, $now), $expiresAt, $lifetime, $now);
    }

    public function isReading(): bool
    {
        foreach ($this->results as $result) {
            if ($result->state === ResultState::Pending || $result->state === ResultState::Busy) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> "name: reason"
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->results as $result) {
            if ($result->error !== null) {
                $errors[] = $result->name . ': ' . $result->error;
            }
        }

        return $errors;
    }
}
