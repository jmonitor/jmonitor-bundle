<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Component;

final class ComponentState
{
    /**
     * @param list<InstanceState> $instances
     * @param int                 $unread    the instances listed without being read
     */
    public function __construct(
        public readonly Component $component,
        public readonly array $instances,
        public readonly int $unread,
    ) {}
}
