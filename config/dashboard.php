<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Controller\DashboardController;
use Jmonitor\JmonitorBundle\Dashboard\DashboardAccess;
use Jmonitor\JmonitorBundle\Dashboard\RequestsScreenFactory;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services();

    $services->set(DashboardAccess::class)
        ->args([
            param('jmonitor.dashboard.enabled'),
            param('kernel.debug'),
            param('jmonitor.dashboard.permission'),
            service('security.authorization_checker')->nullOnInvalid(),
        ]);

    $services->set(DashboardController::class)
        ->public()
        ->args([
            service(DashboardAccess::class),
            service(RequestsScreenFactory::class)->nullOnInvalid(),
            service(Flusher::class)->nullOnInvalid(),
            service('twig')->nullOnInvalid(),
        ]);

    if ($builder->getParameter('jmonitor.dashboard.enabled') === false) {
        return;
    }

    $customLogger = $builder->getParameter('jmonitor.logger');
    $screens = $services->set(RequestsScreenFactory::class)
        ->args([
            service(ReaderInterface::class)->nullOnInvalid(),
            param('jmonitor.recording.enabled'),
            service('jmonitor.clock'),
            \is_string($customLogger) ? service($customLogger) : service('logger')->nullOnInvalid(),
        ]);

    if ($customLogger === null) {
        $screens->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    $services->set(JmonitorExtension::class)
        ->tag('twig.extension');
};
