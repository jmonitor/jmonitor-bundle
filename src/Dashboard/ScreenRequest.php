<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

final class ScreenRequest
{
    /** The query parameter naming the instance a screen shows. */
    public const INSTANCE = 'connection';

    /**
     * @param bool    $fragment the content is asked again for a page already shown
     * @param ?string $instance the instance asked for, by its name
     */
    public function __construct(
        public readonly int $now,
        public readonly bool $fragment = false,
        public readonly ?string $instance = null,
    ) {}
}
