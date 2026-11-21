<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Metric;

enum SupportStatus
{
    case Active;
    case SecurityOnly;
    case Ended;
}
