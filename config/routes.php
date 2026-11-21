<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Controller\DashboardController;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('jmonitor_dashboard', '/')
        ->controller([DashboardController::class, 'index'])
        ->defaults([RequestRecorder::NOT_RECORDED => true])
        ->methods(['GET']);

    $routes->add('jmonitor_dashboard_fragment', '/_fragment')
        ->controller([DashboardController::class, 'fragment'])
        ->defaults([RequestRecorder::NOT_RECORDED => true])
        ->methods(['GET']);

    $routes->add('jmonitor_screen_fragment', '/_fragment/{screen}')
        ->controller([DashboardController::class, 'screenFragment'])
        ->requirements(['screen' => '[a-z][a-z-]*(/[a-z][a-z-]*)*'])
        ->defaults([RequestRecorder::NOT_RECORDED => true])
        ->methods(['GET']);

    $routes->add('jmonitor_screen', '/{screen}')
        ->controller([DashboardController::class, 'screen'])
        ->requirements(['screen' => '[a-z][a-z-]*(/[a-z][a-z-]*)*'])
        ->defaults([RequestRecorder::NOT_RECORDED => true])
        ->methods(['GET']);
};
