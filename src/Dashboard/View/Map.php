<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Map
{
    /**
     * @param list<MapBox> $incoming what starts the code
     * @param list<MapBox> $inside   what happens in the code
     * @param list<MapBox> $outgoing what the code waits for
     */
    public function __construct(
        public readonly array $incoming = [],
        public readonly array $inside = [],
        public readonly ?StackGlance $runsOn = null,
        public readonly array $outgoing = [],
    ) {}
}
