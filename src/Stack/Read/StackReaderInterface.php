<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Component;

interface StackReaderInterface
{
    public function overview(ReadMode $mode): StackState;

    /**
     * An unknown, disabled or undetected component gives a state without component, and so does an unknown
     * instance of a component read on the web server. An unknown instance of a remote server, such as a
     * database, gives its component with every instance, none read.
     */
    public function component(Component $component, ?string $instance, ReadMode $mode): StackState;

    /**
     * The messages waiting in each transport of Messenger, as a state whose only component holds the "queues" instance.
     * Null when they are not counted.
     */
    public function queues(ReadMode $mode): ?StackState;

    /**
     * @return bool false when nothing was forgotten: too soon after the previous one, or no working cache
     */
    public function refresh(?Component $component = null): bool;
}
