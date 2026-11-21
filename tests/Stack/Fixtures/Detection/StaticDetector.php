<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentDetectorInterface;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;

final class StaticDetector implements ComponentDetectorInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly Component $component,
        private readonly Detection $detection,
    ) {}

    public function component(): Component
    {
        return $this->component;
    }

    public function detect(): Detection
    {
        $this->calls++;

        return $this->detection;
    }
}
