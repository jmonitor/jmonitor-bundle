<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Component;

interface ComponentDetectorInterface
{
    public function component(): Component;

    public function detect(): Detection;
}
