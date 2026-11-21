<?php

declare(strict_types=1);

use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Jmonitor\JmonitorBundle\EasyAdmin\Access;
use Jmonitor\JmonitorBundle\EasyAdmin\JmonitorController;
use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(Access::class)
        ->args([
            param('jmonitor.easyadmin.permission_required'),
            param('jmonitor.easyadmin.permission'),
            service('security.authorization_checker')->nullOnInvalid(),
        ]);

    $services->set(JmonitorController::class)
        ->args([
            service(JmonitorPage::class),
            service_locator([
                'twig' => service('twig'),
                'router' => service('router'),
                Access::class => service(Access::class),
            ]),
        ])
        ->tag('controller.service_arguments')
        ->tag(EasyAdminBundle::TAG_ADMIN_ROUTE_CONTROLLER);
};
