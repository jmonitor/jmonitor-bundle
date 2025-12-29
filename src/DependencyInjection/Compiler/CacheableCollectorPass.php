<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection\Compiler;

use Jmonitor\JmonitorBundle\Collector\Components\CacheableComponentCollector;
use Jmonitor\JmonitorBundle\Collector\Components\CacheableComponentCollectorInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class CacheableCollectorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('cache.app')) {
            return;
        }

        $taggedServices = $container->findTaggedServiceIds('jmonitor.cacheable_collector');

        foreach ($taggedServices as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

            if (!class_exists($class) || !is_subclass_of($class, CacheableComponentCollectorInterface::class)) {
                continue;
            }

            $container->register($id . '.cacheable', CacheableComponentCollector::class)
                ->setDecoratedService($id, $id . '.cacheable.inner')
                ->addArgument(new Reference($id . '.cacheable.inner'))
                ->addArgument(new Reference('cache.app'))
                ->setPublic(false);
        }
    }
}
