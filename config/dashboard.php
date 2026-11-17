<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Controller\DashboardController;
use Jmonitor\JmonitorBundle\Dashboard\DashboardAccess;
use Jmonitor\JmonitorBundle\Dashboard\RequestsScreenFactory;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DashboardAccess::class)
        ->args([
            param('jmonitor.dashboard.enabled'),
            param('kernel.debug'),
            param('jmonitor.dashboard.permission'),
            service('security.authorization_checker')->nullOnInvalid(),
        ]);

    $services->set(RequestsScreenFactory::class)
        ->args([
            service(ReaderInterface::class)->nullOnInvalid(),
            param('jmonitor.recording.enabled'),
            service('jmonitor.clock'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'jmonitor']);

    $services->set(JmonitorExtension::class)
        ->tag('twig.extension');

    $services->set(DashboardController::class)
        ->public()
        ->args([
            service(DashboardAccess::class),
            service(RequestsScreenFactory::class),
            service(Flusher::class)->nullOnInvalid(),
            service('twig'),
        ]);
};
