<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class CheckLine
{
    /**
     * @param string                $where the component, and its instance when it has several
     * @param ?string               $path  the JMonitor path of its detail
     * @param ?string               $age   how old the data it judges is
     * @param array<string, string> $query what the link to its detail carries
     */
    public function __construct(
        public readonly string $id,
        public readonly Mark $mark,
        public readonly string $sentence,
        public readonly string $explanation,
        public readonly string $where,
        public readonly ?string $path = null,
        public readonly ?string $age = null,
        public readonly array $query = [],
    ) {}
}
