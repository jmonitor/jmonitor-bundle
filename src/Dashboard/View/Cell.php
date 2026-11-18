<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Cell
{
    /**
     * @param 'warn'|'bad'|'zero'|null $tone
     * @param 'text'|'route'|'sql'     $kind
     * @param array<string, string>    $data
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $tone = null,
        public readonly string $kind = 'text',
        public readonly array $data = [],
    ) {}
}
