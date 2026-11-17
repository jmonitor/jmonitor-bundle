<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Dbal\SchemaAssetFilter;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $connectionName = $builder->getParameter('jmonitor.storage.connection');
    $connection = service(sprintf('doctrine.dbal.%s_connection', $connectionName));
    $services = $container->services();

    $services->set('jmonitor.clock', NativeClock::class);

    $services->set(FileBuffer::class)
        ->args([
            param('jmonitor.recording.buffer_dir'),
            param('jmonitor.recording.app_id'),
            param('jmonitor.recording.flush_interval'),
            service('jmonitor.clock'),
        ]);
    $services->alias(BufferInterface::class, FileBuffer::class);

    $services->set(Aggregator::class)
        ->args([param('jmonitor.recording.slow_threshold')]);

    $services->set(DbalStorage::class)
        ->args([$connection, param('jmonitor.storage.retention')])
        ->lazy(StorageInterface::class);
    $services->alias(StorageInterface::class, DbalStorage::class);

    $services->set(DbalReader::class)
        ->args([$connection]);
    $services->alias(ReaderInterface::class, DbalReader::class);

    $services->set(SchemaAssetFilter::class)
        ->tag('doctrine.dbal.schema_filter', ['connection' => $connectionName]);

    $services->set(FlushLock::class)
        ->args([
            param('jmonitor.recording.buffer_dir'),
            param('jmonitor.recording.app_id'),
            param('jmonitor.recording.flush_interval'),
            service('jmonitor.clock'),
        ]);

    $services->set(Flusher::class)
        ->args([
            service(BufferInterface::class),
            service(Aggregator::class),
            service(StorageInterface::class),
            service(FlushLock::class),
            service('jmonitor.clock'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'jmonitor']);

    if (!$builder->getParameter('jmonitor.recording.enabled')) {
        return;
    }

    $services->set(RequestRecorder::class)
        ->args([
            service(BufferInterface::class),
            service(Flusher::class),
            param('jmonitor.recording.ignore_routes'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.event_subscriber')
        ->tag('monolog.logger', ['channel' => 'jmonitor']);
};
