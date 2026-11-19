<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class ScreenView
{
    /**
     * @param list<Card>  $cards
     * @param list<Table> $tables
     */
    public function __construct(
        public readonly array $cards = [],
        public readonly array $tables = [],
    ) {}

    public function isEmpty(): bool
    {
        return !isset($this->tables[0]) || $this->tables[0]->rows === [];
    }
}
