<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Recording\Entry;

final class Cell
{
    public const SPLIT_WIDTH = 96;
    private const PARTS = ['php' => 'PHP', 'sql' => 'SQL', 'http' => 'HTTP out'];
    private const SPLIT_GAP = 2;

    /**
     * @param 'warn'|'bad'|'zero'|null                         $tone
     * @param 'text'|'code'|'route'|'sql'|'bar'|'split'|'tags' $kind
     * @param array<string, string>                            $data
     * @param array<'php'|'sql'|'http', float>                 $parts the three parts of a split, in ms
     * @param list<Tag>                                        $tags
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $tone = null,
        public readonly string $kind = 'text',
        public readonly array $data = [],
        public readonly array $parts = [],
        public readonly array $tags = [],
    ) {}

    public static function route(string $methodAndName): self
    {
        [$method, $name] = array_pad(explode(' ', $methodAndName, 2), 2, '');

        return new self($name, kind: 'route', data: ['method' => $method]);
    }

    public static function entryPoint(string $unitType, string $unitKey): self
    {
        return match ($unitType) {
            Entry::TYPE_REQUEST => self::route($unitKey),
            Entry::TYPE_COMMAND => new self($unitKey, kind: 'route', data: ['method' => 'CLI']),
            Entry::TYPE_MESSAGE => new self($unitKey, kind: 'route', data: ['method' => 'MSG']),
            Entry::TYPE_TASK => new self(TaskKey::parse($unitKey)->label ?? $unitKey, kind: 'route', data: ['method' => 'TASK']),
            default => new self($unitKey, kind: 'route', data: ['method' => '']),
        };
    }

    /**
     * @param float $share of the largest value shown, from 0 to 1
     */
    public static function bar(string $text, float $share): self
    {
        $share = is_finite($share) ? max(0.0, min(1.0, $share)) : 0.0;

        return new self($text, kind: 'bar', data: ['share' => (string) (int) round($share * 100)]);
    }

    /**
     * @param array{php: float, sql: float, http: float} $parts in ms
     */
    public static function split(array $parts, string $text): self
    {
        $kept = [];

        foreach (array_keys(self::PARTS) as $part) {
            $value = (float) $parts[$part];
            $kept[$part] = is_finite($value) && $value > 0 ? $value : 0.0;
        }

        $spoken = implode(', ', array_map(static fn(string $part, string $name): string => $name . ' ' . Format::duration($kept[$part]), array_keys(self::PARTS), self::PARTS));

        return new self($text, kind: 'split', data: ['spoken' => $spoken], parts: $kept);
    }

    /** @param list<Tag> $tags */
    public static function tags(array $tags): self
    {
        return new self('', kind: 'tags', tags: $tags);
    }

    /**
     * @return list<array{part: string, x: float, width: float}> the parts drawn on a split, in a box SPLIT_WIDTH wide
     */
    public function segments(): array
    {
        $drawn = array_filter($this->parts, static fn(float $value): bool => $value > 0);
        $total = array_sum($drawn);

        if ($total <= 0) {
            return [];
        }

        $room = self::SPLIT_WIDTH - self::SPLIT_GAP * (\count($drawn) - 1);
        $segments = [];
        $x = 0.0;

        foreach ($drawn as $part => $value) {
            $width = round($room * $value / $total, 2);
            $segments[] = ['part' => $part, 'x' => round($x, 2), 'width' => $width];
            $x += $width + self::SPLIT_GAP;
        }

        return $segments;
    }
}
