<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection;

use Jmonitor\JmonitorBundle\Dashboard\Screen\MessagesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\SchedulerScreen;
use Jmonitor\JmonitorBundle\Recording\MessengerRecorder;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpCli;
use Jmonitor\JmonitorBundle\Stack\Collector\QueuesCollector;
use Jmonitor\JmonitorBundle\Stack\Command\QueuesCommand;
use Jmonitor\JmonitorBundle\Stack\Detection\QueuesInstance;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Removes, or marks unavailable, what serves a Symfony component that is installed but not enabled in the framework configuration.
 */
final class ComponentAvailabilityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->messenger($container);
        $this->scheduler($container);
    }

    private function messenger(ContainerBuilder $container): void
    {
        if ($container->has('messenger.receiver_locator')) {
            $this->queues($container);

            return;
        }

        $container->removeDefinition(MessengerRecorder::class);

        foreach ([QueuesCommand::class, QueuesInstance::class, QueuesCollector::class, PhpCli::class] as $id) {
            $container->removeDefinition($id);
        }

        if ($container->hasDefinition(MessagesScreen::class)) {
            $container->getDefinition(MessagesScreen::class)->replaceArgument(0, false);
        }
    }

    private function queues(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(QueuesCommand::class)) {
            return;
        }

        $names = [];
        $receivers = [];

        foreach ($container->findTaggedServiceIds('messenger.receiver') as $id => $tags) {
            $dsn = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;

            if (is_string($dsn) && str_starts_with($dsn, 'schedule://')) {
                continue;
            }

            $name = $id;

            foreach ($tags as $tag) {
                if (is_string($tag['alias'] ?? null) && $tag['alias'] !== '') {
                    $name = $tag['alias'];

                    break;
                }
            }

            if (!isset($receivers[$name])) {
                $names[] = $name;
                $receivers[$name] = new Reference($id);
            }
        }

        $container->getDefinition(QueuesCommand::class)
            ->setArgument(0, ServiceLocatorTagPass::register($container, $receivers))
            ->setArgument(1, $names);
    }

    private function scheduler(ContainerBuilder $container): void
    {
        if (!$container->has('scheduler.messenger_transport_factory') && $container->hasDefinition(SchedulerScreen::class)) {
            $container->getDefinition(SchedulerScreen::class)->replaceArgument(0, false);
        }
    }
}
