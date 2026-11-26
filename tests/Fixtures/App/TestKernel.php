<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\EasyAdmin\JmonitorController;
use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\JmonitorBundle;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\QueuesCollector;
use Jmonitor\JmonitorBundle\Stack\Detection\ApacheDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Stack\Detection\FrankenPhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\MysqlDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\NginxDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\PostgresqlDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Command\WorkCommand;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Controller\DemoController;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\EasyAdmin\AdminDashboard;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\EasyAdmin\NonceExtension;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\EasyAdmin\StaffDashboard;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed\BackOfficeController;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed\BackOfficeUrls;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed\GalleryScreen;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed\GallerySection;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed\ProbeScreen;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\BatchPing;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\BatchPingHandler;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\CountedTransportFactory;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\PingHandler;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\QueueTransportFactory;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeStatusPages;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\InlineServiceConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Routing\Loader\Configurator\RouteConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly string $varDir;

    private readonly bool $withSecurity;

    /** @var array<string, mixed> */
    private readonly array $jmonitorConfig;

    private bool $countsInAProcess = false;

    /**
     * @param array<string, mixed>  $jmonitorConfig without a `stack` key, the stack calls neither endoflife.date nor composer, nor counts the queues
     * @param array<string, string> $transports     name => DSN, added to the transports of Messenger
     * @param ?string               $queuesOutput   what the command counting the queues prints, never started but by fromEnvironment()
     * @param array<string, array<string, mixed>> $connections name => DoctrineBundle configuration, added to the "default" connection
     * @param bool                  $probeFirst     the probe of the host, which has an action, comes before every screen of the bundle
     */
    public function __construct(
        string $environment,
        bool $debug,
        array $jmonitorConfig = [],
        private readonly bool $withDoctrine = true,
        bool $withSecurity = false,
        private readonly bool $withTwig = true,
        private readonly bool $withConnectionLogger = false,
        private readonly bool $withHttpClient = true,
        private readonly bool $withProfiler = false,
        private readonly bool $withMessenger = false,
        private readonly bool $withScheduler = false,
        private readonly bool $withPublicInternals = false,
        private readonly bool $withEmbedHost = false,
        private readonly bool $withCsrf = false,
        private readonly bool $withEasyAdmin = false,
        private readonly bool $withCspNonce = false,
        private readonly bool $withOpenBackOffice = false,
        private readonly ?string $cacheApp = null,
        private readonly bool $withGallery = false,
        private readonly ?string $fakeWebServer = null,
        private readonly array $transports = [],
        private readonly ?string $queuesOutput = null,
        private readonly array $connections = [],
        private readonly ?string $redisProvider = null,
        private readonly bool $probeFirst = false,
    ) {
        if ($withEasyAdmin && !$withDoctrine) {
            throw new \LogicException('The EasyAdmin back office needs Doctrine.');
        }
        if ($withEasyAdmin && !$withTwig) {
            throw new \LogicException('The EasyAdmin back office needs Twig.');
        }
        if ($transports !== [] && !$withMessenger && !$withScheduler) {
            throw new \LogicException('Transports need Messenger.');
        }
        if ($connections !== [] && !$withDoctrine) {
            throw new \LogicException('Connections need Doctrine.');
        }
        if ($fakeWebServer !== null && !\in_array($fakeWebServer, ['frankenphp', 'apache', 'nginx'], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown fake web server "%s".', $fakeWebServer));
        }

        parent::__construct($environment, $debug);

        $this->withSecurity = $withSecurity || $withEasyAdmin;
        $this->jmonitorConfig = ($jmonitorConfig ?: self::environmentConfig($withEasyAdmin)) + ['stack' => ['end_of_life' => false, 'symfony' => ['flex' => false, 'messenger' => false]]];

        $this->varDir = sys_get_temp_dir() . '/jmonitor-bundle-tests/'
            . hash('xxh128', serialize([$environment, $debug, $this->jmonitorConfig, $withDoctrine, $withSecurity, $withTwig, $withConnectionLogger, $withHttpClient, $withProfiler, $withMessenger, $withScheduler, $withPublicInternals, $withEmbedHost, $withCsrf, $withEasyAdmin, $withCspNonce, $withOpenBackOffice, $cacheApp, $withGallery, $fakeWebServer, $transports, $queuesOutput, $connections, $redisProvider, $probeFirst]));
    }

    public static function fromEnvironment(): self
    {
        $environment = self::environmentValue('APP_ENV');
        $debug = self::environmentValue('APP_DEBUG');
        $features = array_filter(array_map(trim(...), explode(',', self::environmentValue('JMONITOR_FIXTURE_FEATURES'))));

        $unknown = array_diff($features, ['easyadmin', 'embed', 'gallery', 'messenger', 'mysql', 'postgresql', 'queues', 'redis', 'scheduler', 'security']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(\sprintf('Unknown fixture feature "%s" in JMONITOR_FIXTURE_FEATURES.', implode('", "', $unknown)));
        }

        $withEasyAdmin = \in_array('easyadmin', $features, true);
        $countsQueues = \in_array('queues', $features, true);
        $stack = ['end_of_life' => false, 'symfony' => ['flex' => false, 'messenger' => $countsQueues]];
        $frankenPhpEndpoint = self::environmentValue('JMONITOR_FIXTURE_FRANKENPHP_ENDPOINT');
        if ($frankenPhpEndpoint !== '') {
            $stack['frankenphp'] = ['endpoint' => $frankenPhpEndpoint];
        }
        $mysql = ['url' => '%env(JMONITOR_FIXTURE_MYSQL_URL)%'];
        $postgresql = ['url' => '%env(JMONITOR_FIXTURE_PGSQL_URL)%', 'server_version' => '17'];
        $redis = \in_array('redis', $features, true);

        $kernel = new self(
            $environment !== '' ? $environment : 'dev',
            $debug !== '' ? filter_var($debug, \FILTER_VALIDATE_BOOL) : true,
            self::environmentConfig($withEasyAdmin) + ($countsQueues || $frankenPhpEndpoint !== '' ? ['stack' => $stack] : []),
            withSecurity: \in_array('security', $features, true),
            withMessenger: $countsQueues || \in_array('messenger', $features, true),
            withScheduler: \in_array('scheduler', $features, true),
            withEmbedHost: \in_array('embed', $features, true),
            withEasyAdmin: $withEasyAdmin,
            withGallery: \in_array('gallery', $features, true),
            transports: $countsQueues ? ['counted' => 'counted://3', 'hanging' => 'counted://hanging'] : [],
            cacheApp: $redis ? 'cache.adapter.redis' : null,
            connections: (\in_array('mysql', $features, true) ? ['main' => $mysql, 'reports' => $mysql] : [])
                + (\in_array('postgresql', $features, true) ? ['pg_main' => $postgresql, 'pg_reports' => $postgresql] : []),
            redisProvider: $redis ? '%env(JMONITOR_FIXTURE_REDIS_URL)%' : null,
        );
        $kernel->countsInAProcess = true;

        return $kernel;
    }

    private static function environmentValue(string $name): string
    {
        $value = $_SERVER[$name] ?? getenv($name);

        return \is_string($value) ? $value : '';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();

        if ($this->withTwig) {
            yield new TwigBundle();
        }

        if ($this->withDoctrine) {
            yield new DoctrineBundle();
        }

        if ($this->withSecurity) {
            yield new SecurityBundle();
        }

        if ($this->withEasyAdmin) {
            yield new TwigExtraBundle();
            yield new TwigComponentBundle();
            yield new EasyAdminBundle();
        }

        yield new JmonitorBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getVarDir(): string
    {
        return $this->varDir;
    }

    public function getCacheDir(): string
    {
        return $this->varDir . '/cache/' . $this->environment . ($this->countsInAProcess ? '-processes' : '');
    }

    public function getLogDir(): string
    {
        return $this->varDir . '/log';
    }

    protected function initializeContainer(): void
    {
        if (!is_dir($this->getLogDir())) {
            mkdir($this->getLogDir(), 0o777, true);
        }

        parent::initializeContainer();
    }

    protected function build(ContainerBuilder $container): void
    {
        // Messages a request sends stay queued until a worker consumes them, as with a real broker.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition('messenger.transport.in_memory.factory')) {
                    $container->getDefinition('messenger.transport.in_memory.factory')->clearTag('kernel.reset');
                }
            }
        });

        if (!$this->countsInAProcess) {
            $container->addCompilerPass(new class ($this->queuesOutput ?? '') implements CompilerPassInterface {
                public function __construct(
                    private readonly string $output,
                ) {}

                public function process(ContainerBuilder $container): void
                {
                    if (!$container->hasDefinition(QueuesCollector::class)) {
                        return;
                    }
                    $container->register('jmonitor.tests.queues_runner', FakeProcessRunner::class)
                        ->addMethodCall('answer', [null, $this->output])
                        ->setPublic(true);
                    $container->getDefinition(QueuesCollector::class)->setArgument(0, new Reference('jmonitor.tests.queues_runner'));
                }
            });
        }

        if ($this->fakeWebServer !== null) {
            $container->addCompilerPass(new class ($this->fakeWebServer) implements CompilerPassInterface {
                private const SAPIS = ['frankenphp' => [FrankenPhpDetector::class, 'frankenphp'], 'apache' => [ApacheDetector::class, 'apache2handler']];

                public function __construct(
                    private readonly string $webServer,
                ) {}

                public function process(ContainerBuilder $container): void
                {
                    foreach ([FrankenPhpDetector::class, ApacheDetector::class, NginxDetector::class] as $detector) {
                        if (!$container->hasDefinition($detector)) {
                            continue;
                        }
                        $definition = $container->getDefinition($detector)->setArgument('$fetcher', new Reference(FakeStatusPages::class));
                        if ($detector !== ApacheDetector::class) {
                            $definition->setArgument('$runner', new Reference(FakeProcessRunner::class));
                        }
                    }

                    [$detector, $sapi] = self::SAPIS[$this->webServer] ?? [null, null];
                    if ($detector !== null && $container->hasDefinition($detector)) {
                        $container->getDefinition($detector)->setArgument('$sapi', $sapi);
                    }
                }
            });
        }

        if ($this->withPublicInternals) {
            $container->addCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    foreach ([PageRenderer::class, ScreenRegistry::class, ScreenBuilder::class, DbalReader::class, Flusher::class, 'twig'] as $id) {
                        if (!$container->has($id)) {
                            continue;
                        }
                        if ($container->hasAlias($id)) {
                            $container->getAlias($id)->setPublic(true);
                        }
                        $container->findDefinition($id)->setPublic(true);
                    }
                    if ($container->hasDefinition(JmonitorController::class)) {
                        $container->getDefinition(JmonitorController::class)->setPublic(true);
                    }
                    foreach (['jmonitor.stack.cache', ComponentRegistry::class, SnapshotProvider::class, LocalStackReader::class, CacheProbe::class, SystemDetector::class, PhpDetector::class, SymfonyDetector::class, FrankenPhpDetector::class, ApacheDetector::class, NginxDetector::class, ProcessRunner::class, FakeStatusPages::class, FakeProcessRunner::class, StackChecks::class, StackSummary::class, DoctrineConnections::class, DatabaseInstances::class, MysqlDetector::class, PostgresqlDetector::class, RedisDetector::class, RedisDsns::class] as $id) {
                        if ($container->hasDefinition($id)) {
                            $container->getDefinition($id)->setPublic(true);
                        }
                    }
                    if ($container->hasAlias(StackReaderInterface::class)) {
                        $container->getAlias(StackReaderInterface::class)->setPublic(true);
                    }
                }
            });
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'jmonitor-test',
            'test' => $this->environment === 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
            'http_client' => $this->withHttpClient ? [
                'mock_response_factory' => HttpResponses::class,
                'scoped_clients' => [
                    'flaky.client' => ['base_uri' => HttpResponses::FLAKY_BASE_URI, 'retry_failed' => ['max_retries' => 1, 'delay' => 0]],
                ],
            ] : ['enabled' => false],
            'cache' => ['pools' => [
                'app.plain' => ['adapter' => 'cache.adapter.array'],
                'app.tagged' => ['tags' => true],
                'app.system' => ['adapter' => 'cache.adapter.system'],
                'app.derived' => ['adapter' => 'cache.app'],
            ]],
        ]);

        if ($this->cacheApp !== null) {
            $container->extension('framework', ['cache' => ['app' => $this->cacheApp]]);
        }

        if ($this->redisProvider !== null) {
            $container->extension('framework', ['cache' => ['default_redis_provider' => $this->redisProvider]]);
        }

        if ($this->withProfiler) {
            $container->extension('framework', ['profiler' => ['enabled' => true, 'collect' => false]]);
        }

        if ($this->withMessenger || $this->withScheduler) {
            $container->extension('framework', ['messenger' => [
                'enabled' => true,
                'transports' => [
                    'async' => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 1, 'delay' => 0]],
                    'batch' => ['dsn' => 'queue://'],
                    ...array_map(static fn(string $dsn): array => ['dsn' => $dsn], $this->transports),
                ],
                'routing' => [Ping::class => 'async', BatchPing::class => 'batch'],
            ]]);
        }

        if ($this->withScheduler) {
            $container->extension('framework', ['scheduler' => ['enabled' => true]]);
        }

        $container->extension('jmonitor', $this->jmonitorConfig);

        if ($this->withDoctrine) {
            $default = ['driver' => 'pdo_sqlite', 'path' => $this->varDir . '/data.sqlite'];
            $container->extension('doctrine', [
                'dbal' => $this->connections === [] ? $default : ['default_connection' => 'default', 'connections' => ['default' => $default] + $this->connections],
            ]);
        }

        if ($this->withEasyAdmin) {
            $container->extension('framework', [
                'assets' => [],
                'translator' => ['fallbacks' => ['en']],
                'property_access' => true,
                'form' => true,
            ]);
            $container->import('@TwigBundle/Resources/config/form.php');
            $container->extension('twig_component', [
                'anonymous_template_directory' => 'components/',
                'defaults' => [],
            ]);
            $container->extension('doctrine', ['orm' => ['auto_mapping' => true]]);
        }

        if ($this->withSecurity) {
            $container->extension('security', [
                'password_hashers' => [InMemoryUser::class => 'plaintext'],
                'providers' => [
                    'users' => ['memory' => ['users' => [
                        'admin' => ['password' => 'test', 'roles' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH']],
                        'user' => ['password' => 'test', 'roles' => ['ROLE_USER']],
                        ...$this->withEasyAdmin ? ['super' => ['password' => 'test', 'roles' => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']]] : [],
                    ]]],
                ],
                'firewalls' => [
                    'lazy' => ['pattern' => '^/lazy/', 'lazy' => true, 'http_basic' => ['realm' => 'JMonitor test']],
                    'main' => [
                        'stateless' => true,
                        'http_basic' => ['realm' => 'JMonitor test'],
                        'switch_user' => true,
                        ...$this->withEasyAdmin ? ['logout' => ['path' => '/logout']] : [],
                    ],
                ],
                'access_control' => [
                    ['path' => '^/lazy/me$', 'roles' => 'IS_AUTHENTICATED_FULLY'],
                    ...$this->withEmbedHost ? [['path' => '^/back-office/locked', 'roles' => 'ROLE_ADMIN']] : [],
                    ...$this->withEasyAdmin && !$this->withOpenBackOffice ? [
                        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                        ['path' => '^/staff', 'roles' => 'ROLE_USER'],
                    ] : [],
                ],
            ]);
            $container->extension('framework', ['session' => ['storage_factory_id' => 'session.storage.factory.mock_file']]);
        }

        if ($this->withCsrf) {
            $container->extension('framework', [
                'csrf_protection' => true,
                'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            ]);
        }

        if ($this->withEmbedHost && $this->withTwig) {
            $container->extension('twig', ['paths' => [__DIR__ . '/templates' => 'BackOffice']]);
        }

        $services = $container->services();
        if ($this->withConnectionLogger) {
            $services->set('logger', ConnectionLogger::class)
                ->args([service('doctrine.dbal.default_connection')])
                ->public();
        } else {
            $services->set('logger', Logger::class)
                ->args([LogLevel::WARNING, $this->getLogDir() . '/' . $this->environment . '.log']);
        }
        if ($this->withHttpClient) {
            $services->set(HttpResponses::class);
        }
        if ($this->fakeWebServer !== null) {
            $services->set(FakeStatusPages::class)->public();
            $services->set(FakeProcessRunner::class)->public();
        }
        $services->set(DemoController::class)
            ->args([
                service('doctrine.dbal.default_connection')->nullOnInvalid(),
                service('http_kernel'),
                service('http_client')->nullOnInvalid(),
                service('flaky.client')->nullOnInvalid(),
                service('cache.app'),
                service('messenger.default_bus')->nullOnInvalid(),
                service('jmonitor.stack.cache')->nullOnInvalid(),
            ])
            ->tag('controller.service_arguments');
        $services->set(WorkCommand::class)
            ->args([service('doctrine.dbal.default_connection')->nullOnInvalid()])
            ->tag('console.command');
        if ($this->withMessenger || $this->withScheduler) {
            $services->set(PingHandler::class)
                ->args([service('doctrine.dbal.default_connection')->nullOnInvalid(), service('messenger.default_bus')])
                ->tag('messenger.message_handler');
            $services->set(QueueTransportFactory::class)
                ->tag('messenger.transport_factory');
            $services->set(CountedTransportFactory::class)
                ->tag('messenger.transport_factory');
            $services->set(BatchPingHandler::class)
                ->args([service('doctrine.dbal.default_connection')->nullOnInvalid()])
                ->tag('messenger.message_handler');
        }
        if ($this->withScheduler) {
            $services->set(DemoSchedule::class)
                ->tag('scheduler.schedule_provider', ['name' => 'default']);
            $services->set(PurgeTask::class)
                ->autoconfigure();
        }
        $services->set(CacheConsumer::class)
            ->autowire()
            ->public();
        if ($this->withEmbedHost) {
            $services->set(BackOfficeUrls::class)
                ->args([service('router')]);
            $services->set(BackOfficeController::class)
                ->args([
                    service(JmonitorPage::class),
                    service(BackOfficeUrls::class),
                    service('twig')->nullOnInvalid(),
                    service('security.authorization_checker')->nullOnInvalid(),
                ])
                ->tag('controller.service_arguments');
            $services->set(ProbeScreen::class)
                ->args([$this->varDir . '/probe-runs'])
                ->tag('jmonitor.screen', ['priority' => $this->probeFirst ? 1000 : 10]);
        }
        if ($this->withGallery) {
            $services->set(GalleryScreen::class)
                ->args([$this->varDir . '/gallery-requests'])
                ->tag('jmonitor.screen', ['priority' => 5])
                ->tag('kernel.reset', ['method' => 'reset']);
            $services->set('jmonitor.fixture.gallery_composed', ComposedScreen::class)
                ->args([
                    'gallery-composed',
                    'Gallery composed',
                    'A composed screen with an outline',
                    Space::Application,
                    array_map(static fn(string $id): InlineServiceConfigurator => inline_service(GallerySection::class)->args([$id]), ['failing', 'steady', 'unbriefed']),
                    true,
                ])
                ->tag('jmonitor.screen', ['priority' => 4]);
        }
        if ($this->withEasyAdmin) {
            foreach ([AdminDashboard::class, StaffDashboard::class] as $dashboard) {
                $services->set($dashboard)
                    ->autowire()
                    ->autoconfigure()
                    ->tag('controller.service_arguments');
            }
        }
        if ($this->withCspNonce) {
            $services->set(NonceExtension::class)
                ->tag('twig.extension');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function environmentConfig(bool $withEasyAdmin): array
    {
        $interval = getenv('JMONITOR_FLUSH_INTERVAL');
        $config = [];
        if (\is_string($interval) && $interval !== '') {
            $config['recording'] = ['flush_interval' => (int) $interval];
        }

        return $withEasyAdmin ? $config + ['easyadmin' => true] : $config;
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('app_hello', '/hello/{name}')->controller([DemoController::class, 'hello']);
        $routes->add('app_lazy_hello', '/lazy/hello/{name}')->controller([DemoController::class, 'hello']);
        $routes->add('app_lazy_me', '/lazy/me')->controller([DemoController::class, 'hello'])->defaults(['name' => 'me']);
        $routes->add('app_slow', '/slow')->controller([DemoController::class, 'slow']);
        $routes->add('app_alloc', '/alloc')->controller([DemoController::class, 'alloc']);
        $routes->add('app_boom', '/boom')->controller([DemoController::class, 'boom']);
        $routes->add('app_handled', '/handled')->controller([DemoController::class, 'handled']);
        $routes->add('app_sub_boom', '/sub-boom')->controller([DemoController::class, 'subBoom']);
        $routes->add('app_teapot', '/teapot')->controller([DemoController::class, 'teapot']);
        $routes->add('app_n_plus_one', '/n-plus-one')->controller([DemoController::class, 'nPlusOne']);
        $routes->add('app_sql_error', '/sql-error')->controller([DemoController::class, 'sqlError']);
        $routes->add('app_hostile_query', '/hostile-query')->controller([DemoController::class, 'hostileQuery']);
        $routes->add('app_call', '/call')->controller([DemoController::class, 'call']);
        $routes->add('app_call_flaky', '/call-flaky')->controller([DemoController::class, 'callFlaky']);
        $routes->add('app_call_down', '/call-down')->controller([DemoController::class, 'callDown']);
        $routes->add('app_cached', '/cached')->controller([DemoController::class, 'cached']);
        $routes->add('app_cache_misses', '/cache-misses')->controller([DemoController::class, 'cacheMisses']);
        $routes->add('app_stack_cached', '/stack-cached')->controller([DemoController::class, 'stackCached']);
        $routes->add('app_dispatch', '/dispatch')->controller([DemoController::class, 'dispatch']);
        $routes->import('@JmonitorBundle/config/routes.php')->prefix('/jmonitor');

        if ($this->withEmbedHost) {
            $marked = [JmonitorPage::NOT_RECORDED => true];
            $this->addBackOfficeRoute($routes, 'back_office_monitoring', '/back-office/monitoring/{path}', 'page', 'GET', $marked);
            $this->addBackOfficeRoute($routes, 'back_office_monitoring_fragment', '/back-office/monitoring-fragment/{path}', 'fragment', 'GET', $marked);
            $this->addBackOfficeRoute($routes, 'back_office_monitoring_action', '/back-office/monitoring-action/{path}', 'action', 'POST', $marked);
            $this->addBackOfficeRoute($routes, 'back_office_locked', '/back-office/locked/{path}', 'page', 'GET', $marked);
            $this->addBackOfficeRoute($routes, 'back_office_unmarked', '/back-office/unmarked/{path}', 'page', 'GET', []);
            $this->addBackOfficeRoute($routes, 'back_office_stateless', '/back-office/stateless/{path}', 'page', 'GET', $marked)->stateless();
            $this->addBackOfficeRoute($routes, 'back_office_stateless_action', '/back-office/stateless-action/{path}', 'action', 'POST', $marked)->stateless();
        }

        if ($this->withEasyAdmin) {
            $routes->import('.', 'easyadmin.routes');
        }
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function addBackOfficeRoute(RoutingConfigurator $routes, string $name, string $path, string $method, string $httpMethod, array $defaults): RouteConfigurator
    {
        return $routes->add($name, $path)
            ->controller([BackOfficeController::class, $method])
            ->requirements(['path' => '.*'])
            ->defaults(['path' => ''] + $defaults)
            ->methods([$httpMethod]);
    }
}
