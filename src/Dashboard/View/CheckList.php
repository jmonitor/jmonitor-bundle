<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class CheckList
{
    /** @param list<CheckLine> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly string $emptyTitle = 'Nothing to check',
        public readonly string $emptyText = '',
        public readonly string $title = 'To check',
        public readonly string $footnote = '',
    ) {}
}
