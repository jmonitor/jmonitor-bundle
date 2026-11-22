<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\KernelCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\Utils\UrlFetcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Flex\SymfonyBundle;

final readonly class SymfonyDetector implements ComponentDetectorInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private ProcessRunner $runner,
        private bool $flex,
        private bool $endOfLife,
        private UrlFetcher $fetcher = new UrlFetcher(3),
    ) {}

    public function component(): Component
    {
        return Component::Symfony;
    }

    public function detect(): Detection
    {
        $specs = [new CollectorSpec(new KernelCollector($this->kernel), Lifetime::Dynamic, CostClass::Immediate)];

        if ($this->flex && class_exists(SymfonyBundle::class)) {
            $specs[] = new CollectorSpec(new FlexRecipesCollector($this->runner, $this->kernel->getProjectDir()), Lifetime::Static, CostClass::Slow);
        }

        if ($this->endOfLife) {
            $specs[] = new CollectorSpec(new EndOfLifeCollector('symfony', $this->fetcher), Lifetime::Day, CostClass::Slow, shared: true);
        }

        return Detection::found(new CollectorSet(Component::Symfony, $specs));
    }
}
