<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

/** A check worth a look, told by its severity and its title, with a link to what details it. */
final class Alert
{
    /**
     * @param string                $where the place that details it
     * @param ?string               $path  the JMonitor path of that place
     * @param array<string, string> $query what the link to that place carries
     */
    public function __construct(
        public readonly string $id,
        public readonly Mark $mark,
        public readonly string $title,
        public readonly string $where,
        public readonly ?string $path = null,
        public readonly array $query = [],
    ) {}
}
