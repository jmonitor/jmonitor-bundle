<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Component;

interface StackReaderInterface
{
    public function overview(ReadMode $mode): StackState;

    /**
     * An unknown, disabled or undetected component, or an unknown instance, gives a state without component.
     */
    public function component(Component $component, ?string $instance, ReadMode $mode): StackState;

    /**
     * @return bool false when nothing was forgotten: too soon after the previous one, or no working cache
     */
    public function refresh(?Component $component = null): bool;
}
