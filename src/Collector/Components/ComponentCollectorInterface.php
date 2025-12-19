<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('jmonitor.symfony.component_collector')]
interface ComponentCollectorInterface
{
    public function collect(): array;
}
