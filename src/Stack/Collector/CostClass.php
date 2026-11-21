<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

enum CostClass
{
    case Immediate;
    case Network;
    case Slow;
}
