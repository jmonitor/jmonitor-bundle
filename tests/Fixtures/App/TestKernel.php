<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Jmonitor\JmonitorBundle\JmonitorBundle;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Command\WorkCommand;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Controller\DemoController;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\BatchPing;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\BatchPingHandler;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\PingHandler;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\QueueTransportFactory;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly string $varDir;

    /**
     * @param array<string, mixed> $jmonitorConfig
     */
    public function __construct(
        string $environment,
        bool $debug,
        private readonly array $jmonitorConfig = [],
        private readonly bool $withDoctrine = true,
        private readonly bool $withSecurity = false,
        private readonly bool $withTwig = true,
        private readonly bool $withConnectionLogger = false,
        private readonly bool $withHttpClient = true,
        private readonly bool $withProfiler = false,
        private readonly bool $withMessenger = false,
        private readonly bool $withScheduler = false,
    ) {
        parent::__construct($environment, $debug);

        $this->varDir = sys_get_temp_dir() . '/jmonitor-bundle-tests/'
            . hash('xxh128', serialize([$environment, $debug, $jmonitorConfig, $withDoctrine, $withSecurity, $withTwig, $withConnectionLogger, $withHttpClient, $withProfiler, $withMessenger, $withScheduler]));
    }

    public static function fromEnvironment(): self
    {
        $environment = self::environmentValue('APP_ENV');
        $debug = self::environmentValue('APP_DEBUG');
        $features = array_filter(array_map(trim(...), explode(',', self::environmentValue('JMONITOR_FIXTURE_FEATURES'))));

        $unknown = array_diff($features, ['messenger', 'scheduler', 'security']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(\sprintf('Unknown fixture feature "%s" in JMONITOR_FIXTURE_FEATURES.', implode('", "', $unknown)));
        }

        return new self(
            $environment !== '' ? $environment : 'dev',
            $debug !== '' ? filter_var($debug, \FILTER_VALIDATE_BOOL) : true,
            withSecurity: \in_array('security', $features, true),
            withMessenger: \in_array('messenger', $features, true),
            withScheduler: \in_array('scheduler', $features, true),
        );
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
        return $this->varDir . '/cache/' . $this->environment;
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

        if ($this->withProfiler) {
            $container->extension('framework', ['profiler' => ['enabled' => true, 'collect' => false]]);
        }

        if ($this->withMessenger || $this->withScheduler) {
            $container->extension('framework', ['messenger' => [
                'enabled' => true,
                'transports' => [
                    'async' => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 1, 'delay' => 0]],
                    'batch' => ['dsn' => 'queue://'],
                ],
                'routing' => [Ping::class => 'async', BatchPing::class => 'batch'],
            ]]);
        }

        if ($this->withScheduler) {
            $container->extension('framework', ['scheduler' => ['enabled' => true]]);
        }

        $container->extension('jmonitor', $this->jmonitorConfig ?: $this->environmentConfig());

        if ($this->withDoctrine) {
            $container->extension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'path' => $this->varDir . '/data.sqlite'],
            ]);
        }

        if ($this->withSecurity) {
            $container->extension('security', [
                'password_hashers' => [InMemoryUser::class => 'plaintext'],
                'providers' => [
                    'users' => ['memory' => ['users' => [
                        'admin' => ['password' => 'test', 'roles' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH']],
                        'user' => ['password' => 'test', 'roles' => ['ROLE_USER']],
                    ]]],
                ],
                'firewalls' => [
                    'lazy' => ['pattern' => '^/lazy/', 'lazy' => true, 'http_basic' => ['realm' => 'JMonitor test']],
                    'main' => ['stateless' => true, 'http_basic' => ['realm' => 'JMonitor test'], 'switch_user' => true],
                ],
                'access_control' => [
                    ['path' => '^/lazy/me$', 'roles' => 'IS_AUTHENTICATED_FULLY'],
                ],
            ]);
            $container->extension('framework', ['session' => ['storage_factory_id' => 'session.storage.factory.mock_file']]);
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
        $services->set(DemoController::class)
            ->args([
                service('doctrine.dbal.default_connection')->nullOnInvalid(),
                service('http_kernel'),
                service('http_client')->nullOnInvalid(),
                service('flaky.client')->nullOnInvalid(),
                service('cache.app'),
                service('messenger.default_bus')->nullOnInvalid(),
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
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentConfig(): array
    {
        $interval = getenv('JMONITOR_FLUSH_INTERVAL');

        return \is_string($interval) && $interval !== '' ? ['recording' => ['flush_interval' => (int) $interval]] : [];
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
        $routes->add('app_cached', '/cached')->controller([DemoController::class, 'cached']);
        $routes->add('app_dispatch', '/dispatch')->controller([DemoController::class, 'dispatch']);
        $routes->import('@JmonitorBundle/config/routes.php')->prefix('/jmonitor');
    }
}
