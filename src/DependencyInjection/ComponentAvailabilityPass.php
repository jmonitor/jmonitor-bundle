<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection;

use Jmonitor\JmonitorBundle\Dashboard\Screen\MessagesScreen;
use Jmonitor\JmonitorBundle\Recording\MessengerRecorder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes, or marks unavailable, what serves a Symfony component that is installed but not enabled in the framework configuration.
 */
final class ComponentAvailabilityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->messenger($container);
    }

    private function messenger(ContainerBuilder $container): void
    {
        if ($container->has('messenger.receiver_locator')) {
            return;
        }

        $container->removeDefinition(MessengerRecorder::class);

        if ($container->hasDefinition(MessagesScreen::class)) {
            $container->getDefinition(MessagesScreen::class)->replaceArgument(0, false);
        }
    }
}
