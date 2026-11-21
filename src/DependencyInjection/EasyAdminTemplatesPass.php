<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class EasyAdminTemplatesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('jmonitor.easyadmin.enabled') || !$container->getParameter('jmonitor.easyadmin.enabled')) {
            return;
        }

        if (!$container->hasDefinition('twig.loader.native_filesystem')) {
            return;
        }

        $container->getDefinition('twig.loader.native_filesystem')
            ->addMethodCall('addPath', [\dirname(__DIR__, 2) . '/templates-easyadmin', 'JmonitorEasyAdmin']);
    }
}
