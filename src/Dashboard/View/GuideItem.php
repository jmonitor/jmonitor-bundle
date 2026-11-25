<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class GuideItem
{
    /** @param array<string, string> $links label => path of a screen */
    public function __construct(
        public readonly string $term,
        public readonly string $text,
        public readonly array $links = [],
    ) {}
}
