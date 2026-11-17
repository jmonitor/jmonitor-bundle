<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Controller\DashboardController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('jmonitor_dashboard', '/')
        ->controller([DashboardController::class, 'index'])
        ->methods(['GET']);

    $routes->add('jmonitor_dashboard_fragment', '/_fragment')
        ->controller([DashboardController::class, 'fragment'])
        ->methods(['GET']);
};
