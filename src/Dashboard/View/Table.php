<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Table
{
    /**
     * @param list<Column> $columns
     * @param list<Row>    $rows
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly int $remaining = 0,
        public readonly string $noun = 'row',
    ) {}
}
