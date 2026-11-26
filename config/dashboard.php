<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Controller\DashboardController;
use Jmonitor\JmonitorBundle\Controller\StandaloneUrls;
use Jmonitor\JmonitorBundle\Dashboard\ActionGuard;
use Jmonitor\JmonitorBundle\Dashboard\Assets;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedCommandsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedTasksCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpFailuresCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\DashboardAccess;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ExceptionsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\OverviewScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\UsersScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Dashboard\Section\CacheSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\CommandsSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\HttpSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\TasksSection;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StackGlanceInterface;
use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Stack\Screen\QueuesBlocks;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services();

    $services->set(DashboardAccess::class)
        ->args([
            param('jmonitor.dashboard.enabled'),
            param('kernel.debug'),
            param('jmonitor.dashboard.permission_required'),
            param('jmonitor.dashboard.permission'),
            service('security.authorization_checker')->nullOnInvalid(),
        ]);

    $services->set(StandaloneUrls::class)
        ->args([service('router')->nullOnInvalid()]);

    $services->set(DashboardController::class)
        ->public()
        ->args([
            service(DashboardAccess::class),
            service(JmonitorPage::class),
            service(StandaloneUrls::class),
            service('twig')->nullOnInvalid(),
        ]);

    $services->set(JmonitorPage::class)
        ->public()
        ->args([service_locator([PageRenderer::class => service(PageRenderer::class)])]);

    $services->set(Assets::class);

    $services->set(ActionGuard::class)
        ->args([service('security.csrf.token_manager')->nullOnInvalid()]);

    $customLogger = $builder->getParameter('jmonitor.logger');
    $pageRenderer = $services->set(PageRenderer::class)
        ->args([
            service(ScreenRegistry::class),
            service(ScreenBuilder::class),
            service(Flusher::class)->nullOnInvalid(),
            service('twig')->nullOnInvalid(),
            service(Assets::class),
            service(ActionGuard::class),
            \is_string($customLogger) ? service($customLogger) : service('logger')->nullOnInvalid(),
        ]);

    if ($customLogger === null) {
        $pageRenderer->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    $screenBuilder = $services->set(ScreenBuilder::class)
        ->args([
            service(ReaderInterface::class)->nullOnInvalid(),
            param('jmonitor.recording.enabled'),
            service('jmonitor.clock'),
            service(BufferDirectory::class)->nullOnInvalid(),
            \is_string($customLogger) ? service($customLogger) : service('logger')->nullOnInvalid(),
            service(ApplicationChecks::class),
        ]);

    if ($customLogger === null) {
        $screenBuilder->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    $services->set(ApplicationChecks::class)
        ->args([tagged_iterator('jmonitor.check')]);

    $services->set(ServerErrorsCheck::class)
        ->tag('jmonitor.check');

    $services->set(SlowRequestsCheck::class)
        ->args([param('jmonitor.recording.slow_threshold')])
        ->tag('jmonitor.check');

    $services->set(SlowQueriesCheck::class)
        ->args([param('jmonitor.recording.sql.slow_threshold')])
        ->tag('jmonitor.check');

    $services->set(RepeatedQueriesCheck::class)
        ->args([param('jmonitor.recording.sql.n_plus_one_threshold')])
        ->tag('jmonitor.check');

    $services->set(HttpFailuresCheck::class)
        ->tag('jmonitor.check');

    $services->set(HttpErrorsCheck::class)
        ->tag('jmonitor.check');

    $services->set(CacheHitRateCheck::class)
        ->tag('jmonitor.check');

    $services->set(FailedMessagesCheck::class)
        ->tag('jmonitor.check');

    $services->set(FailedTasksCheck::class)
        ->tag('jmonitor.check');

    $services->set(FailedCommandsCheck::class)
        ->tag('jmonitor.check');

    $services->set(ScreenRegistry::class)
        ->args([tagged_iterator('jmonitor.screen')]);

    $services->set(RequestsScreen::class)
        ->args([service(ApplicationChecks::class), param('jmonitor.recording.slow_threshold')])
        ->tag('jmonitor.screen', ['priority' => 100]);

    $services->set(OverviewScreen::class)
        ->args([
            service(ApplicationChecks::class),
            service(DeclaredTasks::class),
            [
                service(MessagesSection::class),
                service(TasksSection::class),
                service(CommandsSection::class),
                service(CacheSection::class),
                service(HttpSection::class)->ignoreOnInvalid(),
            ],
            [
                service(QueriesScreen::class),
                service(ExceptionsScreen::class),
                service(UsersScreen::class)->ignoreOnInvalid(),
            ],
            service(StackGlanceInterface::class)->nullOnInvalid(),
        ])
        ->tag('jmonitor.screen', ['priority' => 110]);

    $services->set(QueriesScreen::class)
        ->args([
            $builder->getParameter('jmonitor.recording.sql.enabled') !== false,
            service(ApplicationChecks::class),
            param('jmonitor.recording.sql.slow_threshold'),
            param('jmonitor.recording.sql.n_plus_one_threshold'),
        ])
        ->tag('jmonitor.screen', ['priority' => 90]);

    $services->set('jmonitor.screen.cache_http', ComposedScreen::class)
        ->args([
            'cache-http',
            'Cache & HTTP',
            'What your code waits for besides the database',
            Space::Application,
            [service(CacheSection::class), service(HttpSection::class)->ignoreOnInvalid()],
        ])
        ->tag('jmonitor.screen', ['priority' => 80]);

    $services->set(CacheSection::class)
        ->args([param('jmonitor.recording.cache'), service(ApplicationChecks::class)]);

    $services->set(HttpSection::class)
        ->args([param('jmonitor.recording.http_client'), service(ApplicationChecks::class)]);

    $services->set('jmonitor.screen.background', ComposedScreen::class)
        ->args([
            'background',
            'Background',
            'Everything your application does without a visitor waiting for the answer',
            Space::Background,
            [service(MessagesSection::class), service(TasksSection::class), service(CommandsSection::class)],
            true,
        ])
        ->tag('jmonitor.screen', ['priority' => 70]);

    $services->set(MessagesSection::class)
        ->args([true, param('jmonitor.recording.messenger'), service(QueuesBlocks::class)->nullOnInvalid(), service(ApplicationChecks::class)]);

    $declaredTasks = $services->set(DeclaredTasks::class)
        ->args([
            tagged_locator('scheduler.schedule_provider', 'name'),
            \is_string($customLogger) ? service($customLogger) : service('logger')->nullOnInvalid(),
        ]);

    if ($customLogger === null) {
        $declaredTasks->tag('monolog.logger', ['channel' => 'jmonitor']);
    }

    $services->set(TasksSection::class)
        ->args([param('jmonitor.recording.scheduler'), service(DeclaredTasks::class), service(ApplicationChecks::class)]);

    $services->set(CommandsSection::class)
        ->args([param('jmonitor.recording.commands.enabled'), service(ApplicationChecks::class)]);

    $services->set(ExceptionsScreen::class)
        ->args([param('jmonitor.recording.exceptions'), service(ApplicationChecks::class)])
        ->tag('jmonitor.screen', ['priority' => 30]);

    if (isset($builder->getParameter('kernel.bundles')['SecurityBundle'])) {
        $services->set(UsersScreen::class)
            ->args([param('jmonitor.recording.users'), service(ApplicationChecks::class), param('jmonitor.recording.slow_threshold')])
            ->tag('jmonitor.screen', ['priority' => 20]);
    }

    $services->set(JmonitorExtension::class)
        ->tag('twig.extension');
};
