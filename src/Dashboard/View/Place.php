<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Place
{
    public function __construct(
        public readonly string $label,
        public readonly string $path,
        public readonly string $what,
        public readonly string $question,
    ) {}
}
