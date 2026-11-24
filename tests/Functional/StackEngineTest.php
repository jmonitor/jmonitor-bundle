<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Jmonitor\JmonitorBundle\Stack\Component;
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
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FakeHostUrls;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;

final class StackEngineTest extends WebTestCase
{
    private const STACK = ['end_of_life' => false, 'symfony' => ['flex' => false]];

    private const SERVICES = [
        'jmonitor.stack.cache',
        ComponentRegistry::class,
        SnapshotProvider::class,
        LocalStackReader::class,
        CacheProbe::class,
        SystemDetector::class,
        PhpDetector::class,
        SymfonyDetector::class,
        FrankenPhpDetector::class,
        ApacheDetector::class,
        NginxDetector::class,
        MysqlDetector::class,
        PostgresqlDetector::class,
        RedisDetector::class,
        DoctrineConnections::class,
        DatabaseInstances::class,
        RedisDsns::class,
    ];

    private string $bufferDir;

    /** @var list<TestKernel> */
    private array $kernels = [];

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-stack-engine-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testAnApplicationWithoutDatabaseNorRedisHasItsStack(): void
    {
        $container = self::bootKernel(['doctrine' => false])->getContainer();

        $reader = $container->get(StackReaderInterface::class);

        static::assertInstanceOf(LocalStackReader::class, $reader);
        static::assertSame($container->get(LocalStackReader::class), $reader);

        $state = $reader->overview(ReadMode::Page);
        $linux = PHP_OS_FAMILY === 'Linux';

        static::assertSame(
            $linux ? [Component::System, Component::Php, Component::Symfony] : [Component::Php, Component::Symfony],
            array_map(static fn(ComponentState $component): Component => $component->component, $state->components),
        );
        foreach ($state->components as $component) {
            self::assertFreshValues($component);
        }
        if ($linux) {
            static::assertSame(['frankenphp', 'apache', 'nginx', 'mysql', 'postgresql', 'redis'], array_keys($state->notDetected));
        }
    }

    public function testUnderTheCommandLineNoWebServerIsDetected(): void
    {
        $reader = self::bootKernel()->getContainer()->get(StackReaderInterface::class);
        static::assertInstanceOf(StackReaderInterface::class, $reader);

        $state = $reader->overview(ReadMode::Page);

        foreach ([Component::FrankenPhp, Component::Apache, Component::Nginx] as $webServer) {
            static::assertNull($state->component($webServer), $webServer->value);
        }
        static::assertSame(
            [
                'frankenphp' => 'FrankenPHP is not serving this page.',
                'apache' => 'Apache is not serving this page.',
                'nginx' => 'Nginx is not serving this page.',
                'mysql' => 'No MySQL Doctrine connection found.',
                'postgresql' => 'No PostgreSQL Doctrine connection found.',
                'redis' => 'No Redis DSN found: set jmonitor.stack.redis.connections, use Redis for a cache pool or the sessions, or set the REDIS_URL environment variable.',
            ],
            array_diff_key($state->notDetected, [Component::System->value => true]),
        );
    }

    public function testNothingOfTheStackIsBuiltBeforeARead(): void
    {
        $container = $this->bootedTwice()->getContainer();

        $page = $container->get(JmonitorPage::class);
        static::assertInstanceOf(JmonitorPage::class, $page);
        $page->page(Request::create('/'), 'requests', new FakeHostUrls());
        $services = array_values(array_diff(self::SERVICES, ['jmonitor.stack.cache']));

        self::assertNotBuilt($container, $services);
        self::assertBuiltByARead($container, $services);
    }

    #[Group('easyadmin')]
    public function testLoadingTheRoutesUnderEasyAdminBuildsNothingOfTheStack(): void
    {
        $container = $this->bootedTwice(withEasyAdmin: true)->getContainer();

        $router = $container->get('router');
        static::assertInstanceOf(RouterInterface::class, $router);
        static::assertNotNull($router->getRouteCollection()->get('admin_jmonitor_index'));

        self::assertNotBuilt($container, self::SERVICES);
        self::assertBuiltByARead($container, self::SERVICES);
    }

    public function testTheApplicationNeverCollectsOnItsOwn(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]]]);
        $client->disableReboot();

        for ($i = 0; $i < 20; ++$i) {
            $client->request('GET', '/hello/world');
            static::assertResponseIsSuccessful();
        }
        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/jmonitor/requests');
            static::assertResponseIsSuccessful();
        }

        $container = $client->getKernel()->getContainer();
        self::assertNotBuilt($container, [ComponentRegistry::class]);
        self::assertBuiltByARead($container, [ComponentRegistry::class]);
    }

    public function testACacheOutOfOrderLeavesTheImmediateValues(): void
    {
        $reader = self::bootKernel(['cacheApp' => 'cache.adapter.array'])->getContainer()->get(StackReaderInterface::class);
        static::assertInstanceOf(StackReaderInterface::class, $reader);

        $state = $reader->overview(ReadMode::Page);

        static::assertSame(CacheState::OutOfOrder, $state->cache);
        foreach ([Component::Php, Component::Symfony] as $component) {
            $read = $state->component($component);
            static::assertNotNull($read, $component->value);
            self::assertFreshValues($read);
        }
    }

    public function testASecondReadThroughTheContainerReusesTheFirst(): void
    {
        $reader = self::bootKernel()->getContainer()->get(StackReaderInterface::class);
        static::assertInstanceOf(StackReaderInterface::class, $reader);

        $first = $reader->overview(ReadMode::Page);
        // a value collected again would then carry a later second
        $start = (int) microtime(true);

        while ((int) microtime(true) === $start) {
            usleep(1000);
        }

        $second = $reader->overview(ReadMode::Page);

        static::assertSame(CacheState::Working, $second->cache);
        static::assertNotSame([], self::collectedAt($first));
        static::assertNotContains(null, self::collectedAt($first));
        static::assertSame(self::collectedAt($first), self::collectedAt($second));
    }

    public function testDetectingMysqlBuildsNoConnection(): void
    {
        $connections = ['e' => ['url' => 'mysql://jmonitor:jmonitor@127.0.0.1:1/jmonitor', 'mapping_types' => ['enum' => 'string']]];
        $warmUp = self::kernel([], connections: $connections);
        $warmUp->boot();
        $warmUp->shutdown();
        $this->kernels[] = $kernel = self::kernel([], connections: $connections);
        $kernel->boot();
        $container = $kernel->getContainer();
        $reader = $container->get(StackReaderInterface::class);
        static::assertInstanceOf(StackReaderInterface::class, $reader);

        $state = $reader->overview(ReadMode::Page);

        static::assertSame(CacheState::Cold, $state->cache);
        static::assertSame(['e'], array_map(static fn($instance): ?string => $instance->id, $state->component(Component::Mysql)->instances ?? []));
        static::assertFalse($container->initialized('doctrine.dbal.e_connection'));
        static::assertFalse($container->initialized('doctrine.dbal.default_connection'));
    }

    public function testDetectingPostgresqlBuildsNoConnection(): void
    {
        $connections = ['e' => ['url' => 'postgresql://jmonitor:jmonitor@127.0.0.1:1/jmonitor', 'mapping_types' => ['enum' => 'string']]];
        $warmUp = self::kernel([], connections: $connections);
        $warmUp->boot();
        $warmUp->shutdown();
        $this->kernels[] = $kernel = self::kernel([], connections: $connections);
        $kernel->boot();
        $container = $kernel->getContainer();
        $reader = $container->get(StackReaderInterface::class);
        static::assertInstanceOf(StackReaderInterface::class, $reader);

        $state = $reader->overview(ReadMode::Page);

        static::assertSame(CacheState::Cold, $state->cache);
        static::assertSame(['e'], array_map(static fn($instance): ?string => $instance->id, $state->component(Component::Postgresql)->instances ?? []));
        static::assertFalse($container->initialized('doctrine.dbal.e_connection'));
        static::assertFalse($container->initialized('doctrine.dbal.default_connection'));
    }

    public function testTheStackOffLeavesNoService(): void
    {
        $client = static::createClient(['jmonitor' => ['stack' => false]]);
        $container = $client->getKernel()->getContainer();

        static::assertFalse($container->has(StackReaderInterface::class));
        foreach (self::SERVICES as $id) {
            static::assertFalse($container->has($id), $id);
        }

        $client->request('GET', '/jmonitor/requests');
        static::assertResponseStatusCodeSame(200);
    }

    private static function assertFreshValues(ComponentState $component): void
    {
        $snapshot = $component->instances[0]->snapshot ?? null;
        static::assertNotNull($snapshot, $component->component->value);
        static::assertNotSame([], $snapshot->results, $component->component->value);

        foreach ($snapshot->results as $result) {
            static::assertSame(ResultState::Fresh, $result->state, $result->name);
            static::assertNotSame([], $result->data, $result->name);
        }
    }

    /**
     * @return array<string, ?int>
     */
    private static function collectedAt(StackState $state): array
    {
        $collectedAt = [];

        foreach ($state->components as $component) {
            foreach ($component->instances[0]->snapshot->results ?? [] as $name => $result) {
                $collectedAt[$component->component->value . ' ' . $name] = $result->collectedAt;
            }
        }

        return $collectedAt;
    }

    /**
     * @param list<string> $ids
     */
    private static function assertNotBuilt(ContainerInterface $container, array $ids): void
    {
        foreach ($ids as $id) {
            static::assertFalse($container->initialized($id), $id);
        }
    }

    /**
     * @param list<string> $ids
     */
    private static function assertBuiltByARead(ContainerInterface $container, array $ids): void
    {
        $reader = $container->get(StackReaderInterface::class);
        static::assertInstanceOf(StackReaderInterface::class, $reader);
        $reader->overview(ReadMode::Page);

        foreach ($ids as $id) {
            static::assertTrue($container->initialized($id), $id);
        }
    }

    private function bootedTwice(bool $withEasyAdmin = false): TestKernel
    {
        $jmonitor = ['recording' => ['enabled' => true, 'buffer_dir' => $this->bufferDir]] + ($withEasyAdmin ? ['easyadmin' => true] : []);
        $warmUp = self::kernel($jmonitor, withEasyAdmin: $withEasyAdmin);
        $warmUp->boot();
        $warmUp->shutdown();

        $this->kernels[] = $kernel = self::kernel($jmonitor, withEasyAdmin: $withEasyAdmin);
        $kernel->boot();

        return $kernel;
    }

    /**
     * @param array<string, mixed>                $jmonitor
     * @param array<string, array<string, mixed>> $connections
     */
    private static function kernel(array $jmonitor, bool $withDoctrine = true, bool $withEasyAdmin = false, ?string $cacheApp = null, array $connections = []): TestKernel
    {
        $kernel = new TestKernel(
            'test',
            true,
            $jmonitor + ['stack' => self::STACK],
            withDoctrine: $withDoctrine,
            withPublicInternals: true,
            withEasyAdmin: $withEasyAdmin,
            cacheApp: $cacheApp,
            connections: $connections,
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, doctrine?: bool, cacheApp?: string} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return self::kernel($options['jmonitor'] ?? [], $options['doctrine'] ?? true, cacheApp: $options['cacheApp'] ?? null);
    }
}
