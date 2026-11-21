<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
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

    $collectorRunner = $services->set(CollectorRunner::class)
        ->args([$logger]);

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
        $collectorRunner->tag('monolog.logger', ['channel' => 'jmonitor']);
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
};
