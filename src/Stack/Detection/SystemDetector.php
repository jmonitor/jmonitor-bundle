<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\ServerCollector;
use Jmonitor\JmonitorBundle\Stack\Component;

final readonly class SystemDetector implements ComponentDetectorInterface
{
    public function __construct(
        private string $osFamily = PHP_OS_FAMILY,
    ) {}

    public function component(): Component
    {
        return Component::System;
    }

    public function detect(): Detection
    {
        if ($this->osFamily !== 'Linux') {
            return Detection::unavailable(sprintf('Server metrics are only read on Linux, this server runs on %s.', $this->osFamily));
        }

        return Detection::found(new CollectorSet(Component::System, [
            new CollectorSpec(new ServerCollector(), Lifetime::Dynamic, CostClass::Immediate),
        ]));
    }
}
