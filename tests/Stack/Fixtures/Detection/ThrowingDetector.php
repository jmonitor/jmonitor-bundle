<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentDetectorInterface;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;

final class ThrowingDetector implements ComponentDetectorInterface
{
    public function __construct(
        private readonly Component $component,
        private readonly string $message,
    ) {}

    public function component(): Component
    {
        return $this->component;
    }

    public function detect(): Detection
    {
        throw new \InvalidArgumentException($this->message);
    }
}
