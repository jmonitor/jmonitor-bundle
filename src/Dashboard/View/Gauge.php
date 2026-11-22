<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Gauge
{
    /**
     * @param ?float $percent drawn between 0 and 100, nothing drawn when null
     * @param string $value   the text shown
     */
    public function __construct(
        public readonly string $label,
        public readonly ?float $percent,
        public readonly string $value,
        public readonly ?Mark $mark = null,
        public readonly ?string $hint = null,
    ) {}
}
