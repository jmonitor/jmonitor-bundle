<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Vital
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly ?string $hint = null,
    ) {}
}
