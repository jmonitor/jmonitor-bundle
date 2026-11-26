<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class ComponentCard
{
    /**
     * @param list<Vital>           $vitals
     * @param ?string               $path    the JMonitor path of its detail
     * @param bool                  $reading its figures were never read yet
     * @param ?string               $reason  why it could not be read, shown instead of its vitals
     * @param list<Mark>            $badges
     * @param ?int                  $readAt  Unix timestamp of the data shown
     * @param array<string, string> $query   what the link to its detail carries
     * @param ?string               $role    what it is for, in a few words
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Mark $mark,
        public readonly array $vitals = [],
        public readonly ?string $version = null,
        public readonly ?string $path = null,
        public readonly bool $reading = false,
        public readonly ?string $reason = null,
        public readonly array $badges = [],
        public readonly ?string $note = null,
        public readonly ?int $readAt = null,
        public readonly array $query = [],
        public readonly ?string $role = null,
    ) {}
}
