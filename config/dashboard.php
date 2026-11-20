<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Controller\DashboardController;
use Jmonitor\JmonitorBundle\Dashboard\DashboardAccess;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Dashboard\Screen\CacheScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ExceptionsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\HttpClientScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\UsersScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

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
            service(ScreenRegistry::class)->nullOnInvalid(),
            service(ScreenBuilder::class)->nullOnInvalid(),
            service(Flusher::class)->nullOnInvalid(),
            service('twig')->nullOnInvalid(),
        ]);

    if ($builder->getParameter('jmonitor.dashboard.enabled') === false) {
        return;
    }

    $customLogger = $builder->getParameter('jmonitor.logger');
    $screenBuilder = $services->set(ScreenBuilder::class)
        ->args([
            service(ReaderInterface::class)->nullOnInvalid(),
            param('jmonitor.recording.enabled'),
            service('jmonitor.clock'),
            service(BufferDirectory::class)->nullOnInvalid(),
            \is_string($customLogger) ? service($customLogger) : service('logger')->nullOnInvalid(),
        ]);

    if ($customLogger === null) {
        $screenBuilder->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    $services->set(ScreenRegistry::class)
        ->args([tagged_iterator('jmonitor.screen')]);

    $services->set(RequestsScreen::class)
        ->tag('jmonitor.screen', ['priority' => 100]);

    $services->set(QueriesScreen::class)
        ->args([
            $builder->getParameter('jmonitor.recording.sql.enabled') !== false,
            param('jmonitor.recording.sql.slow_threshold'),
            param('jmonitor.recording.sql.n_plus_one_threshold'),
        ])
        ->tag('jmonitor.screen', ['priority' => 90]);

    $services->set(HttpClientScreen::class)
        ->args([param('jmonitor.recording.http_client')])
        ->tag('jmonitor.screen', ['priority' => 50]);

    $services->set(CacheScreen::class)
        ->args([param('jmonitor.recording.cache')])
        ->tag('jmonitor.screen', ['priority' => 40]);

    $services->set(ExceptionsScreen::class)
        ->args([param('jmonitor.recording.exceptions')])
        ->tag('jmonitor.screen', ['priority' => 30]);

    if (isset($builder->getParameter('kernel.bundles')['SecurityBundle'])) {
        $services->set(UsersScreen::class)
            ->args([param('jmonitor.recording.users'), param('jmonitor.recording.slow_threshold')])
            ->tag('jmonitor.screen', ['priority' => 20]);
    }

    $services->set(JmonitorExtension::class)
        ->args([service('request_stack')->nullOnInvalid()])
        ->tag('twig.extension');
};
