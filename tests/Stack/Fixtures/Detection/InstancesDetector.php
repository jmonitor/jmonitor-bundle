<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentDetectorInterface;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;

/**
 * Built from scalars only, so the compiled test container can instantiate it.
 */
final class InstancesDetector implements ComponentDetectorInterface
{
    /**
     * @param non-empty-list<array{id: string, detail?: ?string, error?: string}> $instances
     */
    public function __construct(
        private readonly Component $component,
        private readonly array $instances,
    ) {}

    public function component(): Component
    {
        return $this->component;
    }

    public function detect(): Detection
    {
        return Detection::instances(array_map(function (array $instance): Instance {
            if (isset($instance['error'])) {
                return Instance::unavailable($this->component, $instance['error'], $instance['id'], $instance['detail'] ?? null);
            }

            $collector = new FakeCollector($this->component->value . '.variables', ['version' => '8.4.0']);
            $set = new CollectorSet($this->component, [new CollectorSpec($collector, Lifetime::Static, CostClass::Immediate)]);

            return Instance::found($this->component, $set, $instance['id'], $instance['detail'] ?? null);
        }, $this->instances));
    }
}
