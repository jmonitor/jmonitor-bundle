<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

final readonly class MysqlSlowQuery
{
    /**
     * @param string $text the digest of the query, its values replaced by "?"
     */
    public function __construct(
        public string $text,
        public int $executions,
        public ?int $averageMs,
        public ?int $maxMs,
        public ?int $totalMs,
    ) {}
}
