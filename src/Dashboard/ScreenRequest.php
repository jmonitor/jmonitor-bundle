<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

final class ScreenRequest
{
    /** @param bool $fragment the content is asked again for a page already shown */
    public function __construct(
        public readonly int $now,
        public readonly bool $fragment = false,
    ) {}
}
