<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

final class Entry
{
    public const TYPE_REQUEST = 'request';
    public const TYPE_QUERY = 'q';
    public const TYPE_NPLUS1 = 'n1';
    public const KEY_SEPARATOR = "\x1f";

    /**
     * @param array<string, int|float|string> $attributes
     * @param list<Entry> $children
     */
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly float $startedAt,
        public readonly float $durationMs,
        public readonly array $attributes = [],
        public readonly array $children = [],
    ) {}

    public function toLine(): string
    {
        return json_encode(
            $this->toArray(),
            \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
        );
    }

    public static function fromLine(string $line): ?self
    {
        try {
            $data = json_decode($line, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? self::fromArray($data) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(): array
    {
        $data = ['t' => $this->type, 'k' => $this->key, 's' => $this->startedAt, 'd' => $this->durationMs];

        if ($this->attributes !== []) {
            $data['a'] = $this->attributes;
        }

        if ($this->children !== []) {
            $data['c'] = array_map(fn(self $child): array => $child->toChildArray($this->startedAt), $this->children);
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private function toChildArray(float $parentStart): array
    {
        $attributes = $this->attributes;
        $hasSlow = isset($attributes['sl']);
        $differentStart = $this->startedAt !== $parentStart;
        $needsTail = $this->type !== self::TYPE_QUERY || $differentStart;

        if ($this->children !== []
            || !isset($attributes['n'], $attributes['m'])
            || \count($attributes) !== ($hasSlow ? 3 : 2)
            || $needsTail && !$hasSlow
        ) {
            return $this->toArray();
        }

        $row = [$this->key, round($this->durationMs, 2), $attributes['n'], round((float) $attributes['m'], 2)];
        if ($hasSlow) {
            $row[] = $attributes['sl'];
        }
        if ($needsTail) {
            $row[] = $this->type;
        }
        if ($differentStart) {
            $row[] = $this->startedAt;
        }

        return $row;
    }

    /**
     * @param array<mixed> $row
     */
    private static function fromCompactChild(array $row, float $parentStart): ?self
    {
        $count = \count($row);
        if ($count < 4 || $count > 7) {
            return null;
        }

        [$key, $duration, $occurrences, $max] = $row;
        $slow = $row[4] ?? null;
        $type = $row[5] ?? self::TYPE_QUERY;
        $start = $row[6] ?? $parentStart;

        if (!\is_string($key) || !\is_string($type)
            || !\is_int($occurrences) || !\is_int($slow) && $count > 4
            || !\is_int($duration) && !\is_float($duration)
            || !\is_int($max) && !\is_float($max)
            || !\is_int($start) && !\is_float($start)
        ) {
            return null;
        }

        $attributes = ['n' => $occurrences, 'm' => (float) $max];
        if ($slow !== null) {
            $attributes['sl'] = $slow;
        }

        return new self($type, $key, (float) $start, (float) $duration, $attributes);
    }

    /**
     * @param array<mixed> $data
     */
    private static function fromArray(array $data, ?float $parentStart = null): ?self
    {
        $data['s'] ??= $parentStart;

        if (!isset($data['t'], $data['k'], $data['s'], $data['d'])
            || !\is_string($data['t']) || !\is_string($data['k'])
            || !\is_int($data['s']) && !\is_float($data['s'])
            || !\is_int($data['d']) && !\is_float($data['d'])
        ) {
            return null;
        }

        $attributes = $data['a'] ?? [];
        $rawChildren = $data['c'] ?? [];

        if (!\is_array($attributes) || !\is_array($rawChildren)) {
            return null;
        }

        $children = [];
        foreach ($rawChildren as $rawChild) {
            $child = match (true) {
                !\is_array($rawChild) => null,
                array_is_list($rawChild) => self::fromCompactChild($rawChild, (float) $data['s']),
                default => self::fromArray($rawChild, (float) $data['s']),
            };
            if ($child === null) {
                return null;
            }
            $children[] = $child;
        }

        return new self($data['t'], $data['k'], (float) $data['s'], (float) $data['d'], $attributes, $children);
    }
}
