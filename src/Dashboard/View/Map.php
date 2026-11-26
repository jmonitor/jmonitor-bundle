<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Map
{
    /**
     * @param list<MapBox> $incoming what starts the code
     * @param ?MapBox      $average  an average request, null when no part of the application is shown
     * @param ?Cell        $split    where that average goes
     * @param list<MapBox> $inside   what happens in the code
     * @param list<MapBox> $outgoing what the code waits for
     */
    public function __construct(
        public readonly array $incoming = [],
        public readonly ?MapBox $average = null,
        public readonly ?Cell $split = null,
        public readonly array $inside = [],
        public readonly ?StackGlance $runsOn = null,
        public readonly array $outgoing = [],
    ) {}
}
