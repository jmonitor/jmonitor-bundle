<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ServerDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\SymfonyDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\StackScreen;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
            ]),
        ])
        ->tag('jmonitor.screen', ['priority' => 10]);
};
