<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

enum SetupResult
{
    case Created;
    case Upgraded;
    case Unchanged;
}
