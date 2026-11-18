<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Column
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $numeric = true,
        public readonly ?string $sort = null,
    ) {}
}
