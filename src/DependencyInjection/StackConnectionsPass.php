<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection;

use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the live stack the parameters of the Doctrine connections, which it reads without building them.
 */
final class StackConnectionsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.connections') || !$container->hasDefinition(DoctrineConnections::class)) {
            return;
        }

        /** @var array<string, string> $connections */
        $connections = $container->getParameter('doctrine.connections');
        $params = [];

        foreach ($connections as $name => $id) {
            if (!$container->hasDefinition($id)) {
                continue;
            }

            $paramsId = 'jmonitor.stack.connection_params.' . $name;
            $container->setDefinition($paramsId, (new Definition(\ArrayObject::class, [$container->getDefinition($id)->getArgument(0)]))->setPublic(false));
            $params[(string) $name] = new Reference($paramsId);
        }

        $container->getDefinition(DoctrineConnections::class)
            ->replaceArgument(0, ServiceLocatorTagPass::register($container, $params))
            ->replaceArgument(1, array_map(strval(...), array_keys($connections)))
            ->replaceArgument(2, new Reference('doctrine.dbal.connection_factory.dsn_parser', ContainerInterface::NULL_ON_INVALID_REFERENCE));
    }
}
