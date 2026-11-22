<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Component;

final readonly class Finding
{
    /**
     * @param string $id          "php.opcache_memory", the name to ignore it by
     * @param string $sentence    short, with the value
     * @param string $explanation why it matters and what to do
     * @param ?int   $age         in seconds, when the value judged is outdated
     */
    public function __construct(
        public string $id,
        public Component $component,
        public ?string $instance,
        public Severity $severity,
        public string $sentence,
        public string $explanation,
        public ?int $age = null,
    ) {}
}
