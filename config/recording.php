<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Command\SetupCommand;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Flush\LabelSync;
use Jmonitor\JmonitorBundle\Recording\CommandRecorder;
use Jmonitor\JmonitorBundle\Recording\ExceptionRecorder;
use Jmonitor\JmonitorBundle\Recording\HttpClient\CallRecorder;
use Jmonitor\JmonitorBundle\Recording\MessengerRecorder;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Recording\Sql\Dbal\Middleware;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\UserRecorder;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Dbal\SchemaAssetFilter;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Worker;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $connectionName = $builder->getParameter('jmonitor.storage.connection');
    $connection = service(sprintf('doctrine.dbal.%s_connection', $connectionName));
    $services = $container->services();
    $customLogger = $builder->getParameter('jmonitor.logger');
    $logger = \is_string($customLogger) ? service($customLogger) : service('logger')->nullOnInvalid();
    // The channel tag does not reach a service closure, so the channel logger is referenced by its id.
    $recorderLogger = match (true) {
        \is_string($customLogger) => service_closure($customLogger),
        isset($builder->getParameter('kernel.bundles')['MonologBundle']) => service_closure('monolog.logger.jmonitor')->nullOnInvalid(),
        default => service_closure('logger')->nullOnInvalid(),
    };

    $services->set('jmonitor.clock', NativeClock::class);

    $services->set(BufferDirectory::class)
        ->args([
            param('jmonitor.recording.buffer_dir'),
            param('jmonitor.recording.app_id'),
            $recorderLogger,
        ]);

    $services->set(FileBuffer::class)
        ->args([
            service(BufferDirectory::class),
            param('jmonitor.recording.flush_interval'),
            service('jmonitor.clock'),
        ]);
    $services->alias(BufferInterface::class, FileBuffer::class);

    $services->set(HotKeys::class)
        ->args([service(BufferDirectory::class), service('jmonitor.clock')])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(Aggregator::class)
        ->args([param('jmonitor.recording.slow_threshold'), service(HotKeys::class)]);

    $services->set(DbalStorage::class)
        ->args([$connection, param('jmonitor.storage.retention')])
        ->lazy(StorageInterface::class);
    $services->alias(StorageInterface::class, DbalStorage::class);

    $services->set(DbalReader::class)
        ->args([$connection]);
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
        ])
        ->arg('$hotKeys', service(HotKeys::class));

    if ($customLogger === null) {
        $flusher->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    if ($builder->getParameter('jmonitor.recording.enabled') === false) {
        return;
    }

    $recorder = $services->set(Recorder::class)
        ->args([service(BufferInterface::class), $recorderLogger])
        ->tag('kernel.reset', ['method' => 'reset']);
    $flusher->arg('$recorder', service(Recorder::class));

    if ($builder->getParameter('jmonitor.recording.sql.enabled') !== false) {
        $services->set(LabelStore::class)
            ->args([service(BufferDirectory::class)]);

        $services->set(QueryDigester::class)
            ->args([service(LabelStore::class), param('jmonitor.recording.sql.n_plus_one_threshold')]);

        $services->set(LabelSync::class)
            ->args([service(LabelStore::class), service(HotKeys::class)]);

        $services->set(Middleware::class)
            ->args([service(Recorder::class)])
            ->tag('doctrine.middleware');

        $recorder->args([
            service(BufferInterface::class),
            $recorderLogger,
            null,
            service(QueryDigester::class),
            param('jmonitor.recording.sql.slow_threshold'),
        ]);
        $flusher->arg('$labels', service(LabelSync::class));
    }

    $requestRecorder = $services->set(RequestRecorder::class)
        ->args([
            service(Recorder::class),
            service(Flusher::class),
            param('jmonitor.recording.ignore_routes'),
            $logger,
            param('jmonitor.recording.enabled'),
        ])
        ->tag('kernel.event_subscriber');

    if ($builder->getParameter('jmonitor.recording.exceptions') !== false) {
        $services->set(ExceptionRecorder::class)
            ->args([service(Recorder::class)])
            ->tag('kernel.event_subscriber')
            ->tag('kernel.reset', ['method' => 'reset']);
    }

    if ($builder->getParameter('jmonitor.recording.http_client') !== false) {
        $services->set(CallRecorder::class)
            ->args([service(Recorder::class), $recorderLogger]);
    }

    $commandRecorder = null;
    if ($builder->getParameter('jmonitor.recording.commands.enabled') !== false) {
        $commandRecorder = $services->set(CommandRecorder::class)
            ->args([
                service(Recorder::class),
                service(Flusher::class),
                param('jmonitor.recording.commands.ignore'),
                param('jmonitor.recording.exceptions'),
                $logger,
                param('jmonitor.recording.enabled'),
            ])
            ->tag('kernel.event_subscriber')
            ->tag('kernel.reset', ['method' => 'reset']);
    }

    $messengerRecorder = null;
    if (class_exists(Worker::class)
        && ($builder->getParameter('jmonitor.recording.messenger') !== false || $builder->getParameter('jmonitor.recording.scheduler') !== false)
    ) {
        $messengerRecorder = $services->set(MessengerRecorder::class)
            ->args([
                service(Recorder::class),
                service(Flusher::class),
                param('jmonitor.recording.messenger'),
                param('jmonitor.recording.scheduler'),
                param('jmonitor.recording.exceptions'),
                $logger,
                param('jmonitor.recording.enabled'),
            ])
            ->tag('kernel.event_subscriber')
            ->tag('kernel.reset', ['method' => 'reset']);
    }

    $userRecorder = null;
    if ($builder->getParameter('jmonitor.recording.users') !== false && isset($builder->getParameter('kernel.bundles')['SecurityBundle'])) {
        $userRecorder = $services->set(UserRecorder::class)
            ->args([service(Recorder::class), service('security.untracked_token_storage'), $logger])
            ->tag('kernel.event_subscriber');
    }

    if ($customLogger === null) {
        $recorder->tag('monolog.logger', ['channel' => 'jmonitor']);
        $requestRecorder->tag('monolog.logger', ['channel' => 'jmonitor']);
        $commandRecorder?->tag('monolog.logger', ['channel' => 'jmonitor']);
        $messengerRecorder?->tag('monolog.logger', ['channel' => 'jmonitor']);
        $userRecorder?->tag('monolog.logger', ['channel' => 'jmonitor']);
    }
};
