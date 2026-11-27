<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

/** The title of a page and the word that says how it goes. */
final class Status
{
    public function __construct(
        public readonly string $title,
        public readonly ?Mark $mark = null,
    ) {}
}
