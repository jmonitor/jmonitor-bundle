<?php

declare(strict_types=1);

use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Jmonitor\JmonitorBundle\EasyAdmin\JmonitorController;
use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(JmonitorController::class)
        ->args([
            service(JmonitorPage::class),
            service_locator([
                'twig' => service('twig'),
                'router' => service('router'),
                'security.authorization_checker' => service('security.authorization_checker')->nullOnInvalid(),
            ]),
            param('jmonitor.easyadmin.permission'),
        ])
        ->tag('controller.service_arguments')
        ->tag(EasyAdminBundle::TAG_ADMIN_ROUTE_CONTROLLER);
};
