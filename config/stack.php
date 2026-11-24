<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpCli;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\QueuesCollector;
use Jmonitor\JmonitorBundle\Stack\Command\QueuesCommand;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ApacheDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Stack\Detection\FrankenPhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\MysqlDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\NginxDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\PostgresqlDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\QueuesInstance;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ApacheDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\FrankenPhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\MysqlDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\NginxDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PostgresqlDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ServerDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\SymfonyDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\QueuesBlocks;
use Jmonitor\JmonitorBundle\Stack\Screen\StackScreen;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services();

    $services->set('jmonitor.stack.cache')
        ->parent('cache.app')
        ->private()
        ->tag('cache.pool');

    $customLogger = $builder->getParameter('jmonitor.logger');
    $logger = \is_string($customLogger) ? service($customLogger) : service('logger')->nullOnInvalid();

    $services->set(WebNode::class);

    $services->set(CollectorRunner::class);

    $services->set(DeltaCalculator::class)
        ->args([service('jmonitor.stack.cache'), 10, 300]);

    $services->set(CacheProbe::class)
        ->args([service('jmonitor.stack.cache'), service('jmonitor.clock')]);

    $snapshotProvider = $services->set(SnapshotProvider::class)
        ->args([
            service(CollectorRunner::class),
            service(DeltaCalculator::class),
            service('jmonitor.stack.cache'),
            service('jmonitor.clock'),
            service(WebNode::class),
            $logger,
        ]);

    if ($customLogger === null) {
        $snapshotProvider->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    $services->set(ProcessRunner::class);

    $services->set(SystemDetector::class)
        ->tag('jmonitor.stack.detector', ['index' => Component::System->value]);

    $services->set(PhpDetector::class)
        ->args([param('jmonitor.stack.end_of_life')])
        ->tag('jmonitor.stack.detector', ['index' => Component::Php->value]);

    $services->set(SymfonyDetector::class)
        ->args([
            service('kernel'),
            service(ProcessRunner::class),
            param('jmonitor.stack.symfony.flex'),
            param('jmonitor.stack.end_of_life'),
        ])
        ->tag('jmonitor.stack.detector', ['index' => Component::Symfony->value]);

    $services->set(FrankenPhpDetector::class)
        ->args([param('jmonitor.stack.frankenphp.endpoint'), service(ProcessRunner::class)])
        ->tag('jmonitor.stack.detector', ['index' => Component::FrankenPhp->value]);

    $services->set(ApacheDetector::class)
        ->args([param('jmonitor.stack.apache.endpoint'), service('request_stack'), param('jmonitor.stack.end_of_life')])
        ->tag('jmonitor.stack.detector', ['index' => Component::Apache->value]);

    $services->set(NginxDetector::class)
        ->args([
            param('jmonitor.stack.nginx.endpoint'),
            service('request_stack'),
            service(ProcessRunner::class),
            param('jmonitor.stack.end_of_life'),
        ])
        ->tag('jmonitor.stack.detector', ['index' => Component::Nginx->value]);

    $services->set(MysqlDetector::class)
        ->args([service(DatabaseInstances::class), param('jmonitor.stack.mysql.connections'), param('jmonitor.stack.end_of_life')])
        ->tag('jmonitor.stack.detector', ['index' => Component::Mysql->value]);

    $services->set(PostgresqlDetector::class)
        ->args([
            service(DatabaseInstances::class),
            param('jmonitor.stack.postgresql.connections'),
            param('jmonitor.stack.postgresql.schema'),
            param('jmonitor.stack.end_of_life'),
        ])
        ->tag('jmonitor.stack.detector', ['index' => Component::Postgresql->value]);

    $services->set(RedisDetector::class)
        ->args([service(RedisDsns::class)])
        ->tag('jmonitor.stack.detector', ['index' => Component::Redis->value]);

    $services->set(DoctrineConnections::class)
        ->args([service_locator([]), [], null])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(DatabaseInstances::class)
        ->args([service(DoctrineConnections::class), service('service_container'), [], param('jmonitor.stack.exclude_connections')]);

    $services->set(RedisDsns::class)
        ->args([service_locator([]), [], []])
        ->tag('kernel.reset', ['method' => 'reset']);

    if ($builder->getParameter('jmonitor.stack.symfony.messenger') && interface_exists(MessageCountAwareInterface::class)) {
        $services->set(QueuesCommand::class)
            ->args([service_locator([]), []])
            ->tag('console.command');

        $services->set(PhpCli::class);

        $services->set(QueuesCollector::class)
            ->args([
                service(ProcessRunner::class),
                service(PhpCli::class),
                param('kernel.project_dir'),
                param('kernel.environment'),
                param('kernel.debug'),
            ]);

        $services->set(QueuesInstance::class)
            ->args([service(QueuesCollector::class)]);

        $services->set(QueuesBlocks::class)
            ->args([
                service_locator([StackReaderInterface::class => service(StackReaderInterface::class)]),
                service('jmonitor.clock'),
            ]);
    }

    $services->set(ComponentRegistry::class)
        ->args([tagged_locator('jmonitor.stack.detector', 'index'), param('jmonitor.stack.components')])
        ->tag('kernel.reset', ['method' => 'reset']);

    $reader = $services->set(LocalStackReader::class)
        ->args([
            service(ComponentRegistry::class),
            service(SnapshotProvider::class),
            service(CacheProbe::class),
            service('jmonitor.stack.cache'),
            service('jmonitor.clock'),
            service(WebNode::class),
            $logger,
            service(QueuesInstance::class)->nullOnInvalid(),
        ]);

    if ($customLogger === null) {
        $reader->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    $services->alias(StackReaderInterface::class, LocalStackReader::class);

    $services->set(StackChecks::class)
        ->args([param('jmonitor.stack.ignore_checks'), param('kernel.debug'), service('jmonitor.clock')]);

    $summary = $services->set(StackSummary::class)
        ->args([
            service_locator(['cache' => service('jmonitor.stack.cache')]),
            service(WebNode::class),
            service('jmonitor.clock'),
            $logger,
        ])
        ->tag('kernel.reset', ['method' => 'reset']);

    if ($customLogger === null) {
        $summary->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    $services->set(CardBuilder::class)
        ->args([service('jmonitor.clock')]);

    $services->set(ServerDetail::class);
    $services->set(PhpDetail::class);
    $services->set(SymfonyDetail::class);
    $services->set(FrankenPhpDetail::class);
    $services->set(ApacheDetail::class);
    $services->set(NginxDetail::class);
    $services->set(MysqlDetail::class);
    $services->set(PostgresqlDetail::class);

    $services->set(StackScreen::class)
        ->args([
            service_locator([
                StackReaderInterface::class => service(StackReaderInterface::class),
                StackChecks::class => service(StackChecks::class),
                StackSummary::class => service(StackSummary::class),
            ]),
            service(CardBuilder::class),
            service('jmonitor.clock'),
            service_locator([
                Component::System->value => service(ServerDetail::class),
                Component::Php->value => service(PhpDetail::class),
                Component::Symfony->value => service(SymfonyDetail::class),
                Component::FrankenPhp->value => service(FrankenPhpDetail::class),
                Component::Apache->value => service(ApacheDetail::class),
                Component::Nginx->value => service(NginxDetail::class),
                Component::Mysql->value => service(MysqlDetail::class),
                Component::Postgresql->value => service(PostgresqlDetail::class),
            ]),
        ])
        ->tag('jmonitor.screen', ['priority' => 10]);
};
