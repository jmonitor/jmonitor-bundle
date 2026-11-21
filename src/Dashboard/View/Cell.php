<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Recording\Entry;

final class Cell
{
    /**
     * @param 'warn'|'bad'|'zero'|null    $tone
     * @param 'text'|'code'|'route'|'sql' $kind
     * @param array<string, string>       $data
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $tone = null,
        public readonly string $kind = 'text',
        public readonly array $data = [],
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
}
