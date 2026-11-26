<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Places
{
    /** @param list<Place> $places */
    public function __construct(public readonly array $places) {}
}
