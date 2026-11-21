<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection;

use Jmonitor\JmonitorBundle\Stack\Detection\ComponentDetectorInterface;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class Detectors
{
    /**
     * @param array<string, ComponentDetectorInterface|\Closure(): ComponentDetectorInterface> $detectors  keyed by component value, a closure building the detector when its component is detected
     * @param array<string, array{enabled: 'auto'|bool}>                                       $components
     */
    public static function registry(array $detectors, array $components): ComponentRegistry
    {
        return new ComponentRegistry(new ServiceLocator(array_map(
            static fn(ComponentDetectorInterface|\Closure $detector): \Closure => $detector instanceof \Closure ? $detector : static fn(): ComponentDetectorInterface => $detector,
            $detectors,
        )), $components);
    }
}
