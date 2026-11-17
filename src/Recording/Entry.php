<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

final class Entry
{
    public const TYPE_REQUEST = 'request';

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
            $data['c'] = array_map(static fn(self $child): array => $child->toArray(), $this->children);
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private static function fromArray(array $data): ?self
    {
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
            $child = \is_array($rawChild) ? self::fromArray($rawChild) : null;
            if ($child === null) {
                return null;
            }
            $children[] = $child;
        }

        return new self($data['t'], $data['k'], (float) $data['s'], (float) $data['d'], $attributes, $children);
    }
}
