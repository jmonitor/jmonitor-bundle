<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpRuntimeCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\Utils\UrlFetcher;

final readonly class PhpDetector implements ComponentDetectorInterface
{
    public function __construct(
        private bool $endOfLife,
        private UrlFetcher $fetcher = new UrlFetcher(3),
    ) {}

    public function component(): Component
    {
        return Component::Php;
    }

    public function detect(): Detection
    {
        $specs = [new CollectorSpec(new PhpRuntimeCollector(), Lifetime::Dynamic, CostClass::Immediate, trackDelta: true)];

        if ($this->endOfLife) {
            $specs[] = new CollectorSpec(new EndOfLifeCollector('php', $this->fetcher), Lifetime::Day, CostClass::Slow, shared: true);
        }

        return Detection::found(new CollectorSet(Component::Php, $specs));
    }
}
