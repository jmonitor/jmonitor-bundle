<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Command\SetupCommand;
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

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $connectionName = $builder->getParameter('jmonitor.storage.connection');
    $connection = service(sprintf('doctrine.dbal.%s_connection', $connectionName));
    $services = $container->services();
    $customLogger = $builder->getParameter('jmonitor.logger');
    $logger = \is_string($customLogger) ? service($customLogger) : service('logger')->nullOnInvalid();

    $services->set('jmonitor.clock', NativeClock::class);

    $services->set(BufferDirectory::class)
        ->args([
            param('jmonitor.recording.buffer_dir'),
            param('jmonitor.recording.app_id'),
        ]);

    $services->set(FileBuffer::class)
        ->args([
            service(BufferDirectory::class),
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
        ->args([$connection])
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(ReaderInterface::class, DbalReader::class);

    $services->set(SchemaAssetFilter::class)
        ->tag('doctrine.dbal.schema_filter', ['connection' => $connectionName]);

    $services->set(SetupCommand::class)
        ->args([
            inline_service(DbalStorage::class)->args([$connection, param('jmonitor.storage.retention')]),
            $connectionName,
        ])
        ->tag('console.command');

    $services->set(FlushLock::class)
        ->args([
            service(BufferDirectory::class),
            param('jmonitor.recording.flush_interval'),
            service('jmonitor.clock'),
        ]);

    $flusher = $services->set(Flusher::class)
        ->args([
            service(BufferInterface::class),
            service(Aggregator::class),
            service(StorageInterface::class),
            service(FlushLock::class),
            service('jmonitor.clock'),
            $logger,
        ]);

    if ($customLogger === null) {
        $flusher->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    if ($builder->getParameter('jmonitor.recording.enabled') === false) {
        return;
    }

    $recorder = $services->set(RequestRecorder::class)
        ->args([
            service(BufferInterface::class),
            service(Flusher::class),
            param('jmonitor.recording.ignore_routes'),
            $logger,
            param('jmonitor.recording.enabled'),
        ])
        ->tag('kernel.event_subscriber');

    if ($customLogger === null) {
        $recorder->tag('monolog.logger', ['channel' => 'jmonitor']);
    }
};
