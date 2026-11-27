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
    private const SPLIT_MIN_PART = 1.0;

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

    /**
     * @param bool    $titled     whether the name is repeated as its title, for a column that cuts it
     * @param ?string $definition what the name means, read on hover in place of the repeated name
     */
    public static function route(string $methodAndName, bool $titled = false, ?string $definition = null): self
    {
        [$method, $name] = array_pad(explode(' ', $methodAndName, 2), 2, '');
        $title = $definition ?? ($titled ? $name : null);

        return new self($name, kind: 'route', data: ['method' => $method] + ($title === null ? [] : ['title' => $title]));
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
     * @param string                                     $note  read after the three parts
     */
    public static function split(array $parts, string $text, string $note = ''): self
    {
        $kept = [];

        foreach (array_keys(self::PARTS) as $part) {
            $value = (float) $parts[$part];
            $kept[$part] = is_finite($value) && $value > 0 ? $value : 0.0;
        }

        $spoken = implode(', ', array_map(static fn(string $part, string $name): string => $name . ' ' . ($kept[$part] > 0 ? Format::duration($kept[$part]) : '0 ms'), array_keys(self::PARTS), self::PARTS));

        return new self($text, kind: 'split', data: ['spoken' => $note === '' ? $spoken : $spoken . ', ' . $note], parts: $kept);
    }

    /**
     * The split of an average run between PHP, SQL and outgoing HTTP, PHP being what SQL and HTTP leave.
     *
     * @param float  $average in ms
     * @param ?float $sql     the SQL time of an average run in ms, null when no query was recorded
     * @param float  $queries the queries of an average run
     * @param ?float $http    the outgoing HTTP time of an average run in ms, null when no call was recorded
     */
    public static function averageSplit(float $average, ?float $sql, float $queries, ?float $http): self
    {
        $queriesText = null;

        if ($sql !== null) {
            $rounded = round($queries, 1);
            $queriesText = Format::number($rounded, $rounded < 10 ? 1 : 0) . ' queries';
        }

        if ($sql === null && $http === null) {
            $text = 'PHP only';
        } else {
            $text = $sql !== null && $sql >= ($http ?? 0.0) ? 'SQL ' . Format::duration($sql) : 'HTTP out ' . Format::duration($http ?? 0.0);
            $text = $queriesText === null ? $text : $text . ' · ' . $queriesText;
        }

        return self::split(
            ['php' => max(0.0, $average - ($sql ?? 0.0) - ($http ?? 0.0)), 'sql' => $sql ?? 0.0, 'http' => $http ?? 0.0],
            $text,
            $queriesText ?? '',
        );
    }

    /** @param list<Tag> $tags */
    public static function tags(array $tags): self
    {
        return new self('', kind: 'tags', tags: $tags);
    }

    /**
     * @return list<array{part: string, x: float, width: float}> the parts drawn on a split, filling a box SPLIT_WIDTH wide; the largest gives up what the others need to stay visible
     */
    public function segments(): array
    {
        $drawn = array_filter($this->parts, static fn(float $value): bool => $value > 0);
        $total = array_sum($drawn);

        if ($total <= 0) {
            return [];
        }

        $room = self::SPLIT_WIDTH - self::SPLIT_GAP * (\count($drawn) - 1);
        $widths = array_map(static fn(float $value): float => max(self::SPLIT_MIN_PART, round($room * $value / $total, 2)), $drawn);
        $largest = array_keys($drawn, max($drawn), true)[0];
        $widths[$largest] = round($room - array_sum($widths) + $widths[$largest], 2);
        $segments = [];
        $x = 0.0;

        foreach ($widths as $part => $width) {
            $segments[] = ['part' => $part, 'x' => round($x, 2), 'width' => $width];
            $x += $width + self::SPLIT_GAP;
        }

        return $segments;
    }
}
