<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Column
{
    /**
     * @param ?string $definition what the column means, read on hover
     * @param bool    $legend     whether its header names the parts of its split cells, its label only being read aloud
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $numeric = true,
        public readonly ?string $sort = null,
        public readonly ?string $definition = null,
        public readonly bool $legend = false,
    ) {}
}
