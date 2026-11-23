<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;

final class FakeStackReader implements StackReaderInterface
{
    /** @var list<string> "overview Page", "component php Fragment", "refresh all"… */
    public array $calls = [];

    public function __construct(
        private readonly StackState $state,
        private readonly ?StackState $queues = null,
    ) {}

    public function overview(ReadMode $mode): StackState
    {
        $this->calls[] = 'overview ' . $mode->name;

        return $this->state;
    }

    public function component(Component $component, ?string $instance, ReadMode $mode): StackState
    {
        $this->calls[] = 'component ' . $component->value . ' ' . $mode->name;

        return $this->state;
    }

    public function queues(ReadMode $mode): ?StackState
    {
        $this->calls[] = 'queues ' . $mode->name;

        return $this->queues;
    }

    public function refresh(?Component $component = null): bool
    {
        $this->calls[] = 'refresh ' . ($component->value ?? 'all');

        return true;
    }
}
