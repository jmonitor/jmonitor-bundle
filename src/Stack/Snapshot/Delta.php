<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

/**
 * Difference between two samples of the same collector, keyed by dotted paths.
 */
final class Delta
{
    /** @var array<string, int|float|null> */
    private array $values = [];

    /**
     * @param array<string, mixed> $previous flattened sample
     * @param array<string, mixed> $current  flattened sample
     */
    public function __construct(
        private readonly array $previous,
        private readonly array $current,
        public readonly int $elapsed,
    ) {}

    public function getValue(string $key): int|float|null
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        $current = $this->current[$key] ?? null;
        $previous = $this->previous[$key] ?? null;

        if (!is_numeric($current) || !is_numeric($previous)) {
            return $this->values[$key] = null;
        }

        $delta = $current - $previous;

        return $this->values[$key] = $delta < 0 ? null : $delta;
    }

    public function getPerSec(string $key): ?float
    {
        $delta = $this->getValue($key);

        return $delta === null ? null : round($delta / $this->elapsed, 2);
    }

    /**
     * @return array{previous: array<string, mixed>, current: array<string, mixed>, elapsed: int}
     */
    public function toArray(): array
    {
        return ['previous' => $this->previous, 'current' => $this->current, 'elapsed' => $this->elapsed];
    }

    /**
     * @param array{previous: array<string, mixed>, current: array<string, mixed>, elapsed: int} $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values['previous'], $values['current'], $values['elapsed']);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $result += self::flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }

        return $result;
    }
}
