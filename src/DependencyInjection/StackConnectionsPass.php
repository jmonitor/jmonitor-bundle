<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection;

use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\OutOfBoundsException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the live stack the Doctrine connections, and their parameters to read without building them.
 */
final class StackConnectionsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.connections') || !$container->hasDefinition(DoctrineConnections::class) || !$container->hasDefinition(DatabaseInstances::class)) {
            return;
        }

        /** @var array<string, string> $connections */
        $connections = $container->getParameter('doctrine.connections');
        $params = [];
        $ids = [];

        foreach ($connections as $name => $id) {
            if (!$container->hasDefinition($id)) {
                continue;
            }

            try {
                $connectionParams = $container->getDefinition($id)->getArgument(0);
            } catch (OutOfBoundsException) {
                continue;
            }

            if (!\is_array($connectionParams)) {
                continue;
            }

            $ids[(string) $name] = $id;

            $paramsId = 'jmonitor.stack.connection_params.' . $name;
            $container->setDefinition($paramsId, (new Definition(\ArrayObject::class, [$connectionParams]))->setPublic(false));
            $params[(string) $name] = new Reference($paramsId);
        }

        $container->getDefinition(DoctrineConnections::class)
            ->replaceArgument(0, ServiceLocatorTagPass::register($container, $params))
            ->replaceArgument(1, array_map(strval(...), array_keys($connections)))
            ->replaceArgument(2, new Reference('doctrine.dbal.connection_factory.dsn_parser', ContainerInterface::NULL_ON_INVALID_REFERENCE));

        $container->getDefinition(DatabaseInstances::class)
            ->replaceArgument(2, $ids);
    }
}
