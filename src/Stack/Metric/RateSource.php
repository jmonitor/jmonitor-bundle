<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Metric;

enum RateSource
{
    case Delta;
    case SinceStart;
    case Unavailable;
    /** No previous sample to compare with yet: a later sample gives the value. */
    case Pending;
}
