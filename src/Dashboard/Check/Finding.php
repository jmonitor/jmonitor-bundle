<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

final class Finding
{
    /**
     * @param string  $screen the id of the screen that details it
     * @param ?string $code   the code it points at
     */
    public function __construct(
        public readonly string $id,
        public readonly Severity $severity,
        public readonly string $title,
        public readonly string $sentence,
        public readonly string $why,
        public readonly string $action,
        public readonly string $screen,
        public readonly ?string $code = null,
    ) {}
}
