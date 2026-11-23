<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\QueuesCollector;
use Jmonitor\JmonitorBundle\Stack\Component;

/**
 * The messages waiting in the Messenger transports, an instance of Symfony read on its own.
 */
final readonly class QueuesInstance
{
    public const ID = 'queues';

    public function __construct(
        private QueuesCollector $collector,
    ) {}

    public function instance(): Instance
    {
        return Instance::found(
            Component::Symfony,
            new CollectorSet(Component::Symfony, [new CollectorSpec($this->collector, Lifetime::HalfMinute, CostClass::Slow, shared: true)]),
            self::ID,
        );
    }
}
