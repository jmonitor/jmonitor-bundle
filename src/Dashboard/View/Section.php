<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Section
{
    /**
     * @param list<Block>                             $blocks
     * @param string                                  $sort   the sort applied to its table
     * @param array<string, 'asc'|'desc'>             $sorts  sort id => default direction
     * @param array{title: string, text: string}|null $empty  the line shown in place of its figures
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $summary,
        public readonly array $blocks = [],
        public readonly string $sort = '',
        public readonly string $direction = '',
        public readonly array $sorts = [],
        public readonly ?array $empty = null,
    ) {}

    public function directionFor(string $sort): string
    {
        if ($sort !== $this->sort) {
            return $this->sorts[$sort] ?? 'desc';
        }

        return $this->direction === 'asc' ? 'desc' : 'asc';
    }
}
