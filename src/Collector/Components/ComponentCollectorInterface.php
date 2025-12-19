<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;
interface ComponentCollectorInterface
{
    public function collect(): array;
}
