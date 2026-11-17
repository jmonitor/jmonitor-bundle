<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection;

use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Dbal\SchemaAssetFilter;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class StorageConnectionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('jmonitor.storage.connection')) {
            return;
        }

        if ($container->has(sprintf('doctrine.dbal.%s_connection', $container->getParameter('jmonitor.storage.connection')))) {
            return;
        }

        foreach ([RequestRecorder::class, Flusher::class, DbalStorage::class, DbalReader::class, SchemaAssetFilter::class] as $id) {
            $container->removeDefinition($id);
        }

        foreach ([StorageInterface::class, ReaderInterface::class] as $alias) {
            $container->removeAlias($alias);
        }
    }
}
