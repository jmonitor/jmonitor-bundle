<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Jmonitor\JmonitorBundle\JmonitorBundle;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Controller\DemoController;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
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
    ) {
        parent::__construct($environment, $debug);

        $this->varDir = sys_get_temp_dir() . '/jmonitor-bundle-tests/'
            . hash('xxh128', serialize([$environment, $debug, $jmonitorConfig, $withDoctrine, $withSecurity, $withTwig]));
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

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'jmonitor-test',
            'test' => $this->environment === 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);

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
                        'admin' => ['password' => 'test', 'roles' => ['ROLE_ADMIN']],
                        'user' => ['password' => 'test', 'roles' => ['ROLE_USER']],
                    ]]],
                ],
                'firewalls' => [
                    'main' => ['stateless' => true, 'http_basic' => ['realm' => 'JMonitor test']],
                ],
            ]);
        }

        $services = $container->services();
        $services->set('logger', Logger::class)
            ->args([LogLevel::WARNING, $this->getLogDir() . '/' . $this->environment . '.log']);
        $services->set(DemoController::class)
            ->args([service('doctrine.dbal.default_connection')->nullOnInvalid()])
            ->tag('controller.service_arguments');
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
        $routes->add('app_slow', '/slow')->controller([DemoController::class, 'slow']);
        $routes->add('app_alloc', '/alloc')->controller([DemoController::class, 'alloc']);
        $routes->add('app_boom', '/boom')->controller([DemoController::class, 'boom']);
        $routes->add('app_teapot', '/teapot')->controller([DemoController::class, 'teapot']);
        $routes->add('app_n_plus_one', '/n-plus-one')->controller([DemoController::class, 'nPlusOne']);
        $routes->add('app_sql_error', '/sql-error')->controller([DemoController::class, 'sqlError']);
        $routes->import('@JmonitorBundle/config/routes.php')->prefix('/jmonitor');
    }
}
