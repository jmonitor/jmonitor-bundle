<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Collector\Cloudflare\CloudflareCollector;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
use Jmonitor\Collector\Mysql\MysqlInformationSchemaCollector;
use Jmonitor\Collector\Mysql\MysqlSlowQueriesCollector;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use Jmonitor\Collector\Postgresql\PostgresqlActivityCollector;
use Jmonitor\Collector\Postgresql\PostgresqlDatabaseCollector;
use Jmonitor\Collector\Postgresql\PostgresqlSettingsCollector;
use Jmonitor\Collector\Postgresql\PostgresqlSlowQueriesCollector;
use Jmonitor\Collector\Redis\RedisCollector;
use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\Jmonitor;
use Jmonitor\JmonitorBundle\Collector\Components\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\Collector\Components\MessengerStatsCollector;
use Jmonitor\JmonitorBundle\Collector\Components\SchedulerCollector;
use Jmonitor\JmonitorBundle\Collector\SymfonyCollector;
use Jmonitor\JmonitorBundle\JmonitorBundle;
use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\DependencyInjection\StorageConnectionPass;
use Jmonitor\JmonitorBundle\EasyAdmin\Access;
use Jmonitor\JmonitorBundle\EasyAdmin\JmonitorController;
use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Flush\LabelSync;
use Jmonitor\JmonitorBundle\Recording\CommandRecorder;
use Jmonitor\JmonitorBundle\Recording\ExceptionRecorder;
use Jmonitor\JmonitorBundle\Recording\HttpClient\CallRecorder;
use Jmonitor\JmonitorBundle\Recording\MessengerRecorder;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Recording\Sql\Dbal\Middleware;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\UserRecorder;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;

class JmonitorBundleTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, class-string>|null $bundles
     */
    private function loadBundle(
        array $config,
        string $environment = 'test',
        string $projectDir = '/srv/app',
        ?array $bundles = null,
        bool $debug = false,
    ): ContainerBuilder {
        $container = new ContainerBuilder();
        // Minimal kernel params expected by Symfony's ExtensionTrait
        $container->setParameter('kernel.environment', $environment);
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('kernel.bundles', $bundles ?? ['SecurityBundle' => SecurityBundle::class]);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new JmonitorBundle();
        $extension = $bundle->getContainerExtension();
        // Load configuration and services into the container
        $extension->load([$config], $container);

        return $container;
    }

    public function testRecordingParametersHaveDefaultsWithoutApiKey(): void
    {
        $container = $this->loadBundle([], environment: 'prod');

        static::assertTrue($container->getParameter('jmonitor.recording.enabled'));
        static::assertSame(10, $container->getParameter('jmonitor.recording.flush_interval'));
        static::assertSame(1000, $container->getParameter('jmonitor.recording.slow_threshold'));
        static::assertSame([], $container->getParameter('jmonitor.recording.ignore_routes'));
        static::assertNull($container->getParameter('jmonitor.recording.buffer_dir'));
        static::assertSame('default', $container->getParameter('jmonitor.storage.connection'));
        static::assertSame(604800, $container->getParameter('jmonitor.storage.retention'));
        static::assertTrue($container->getParameter('jmonitor.dashboard.enabled'));
        static::assertNull($container->getParameter('jmonitor.dashboard.permission'));
        static::assertFalse($container->hasDefinition(Jmonitor::class));
    }

    public function testRecordingIsDisabledByDefaultInTestEnvironment(): void
    {
        static::assertFalse($this->loadBundle([], environment: 'test')->getParameter('jmonitor.recording.enabled'));
        static::assertTrue($this->loadBundle(['recording' => ['enabled' => true]], environment: 'test')->getParameter('jmonitor.recording.enabled'));
        static::assertFalse($this->loadBundle(['recording' => ['enabled' => false]], environment: 'prod')->getParameter('jmonitor.recording.enabled'));
    }

    public function testAppIdDependsOnProjectDirAndEnvironment(): void
    {
        $a = $this->loadBundle([], environment: 'prod', projectDir: '/srv/a')->getParameter('jmonitor.recording.app_id');
        $b = $this->loadBundle([], environment: 'prod', projectDir: '/srv/b')->getParameter('jmonitor.recording.app_id');
        $c = $this->loadBundle([], environment: 'dev', projectDir: '/srv/a')->getParameter('jmonitor.recording.app_id');

        static::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $a);
        static::assertNotSame($a, $b);
        static::assertNotSame($a, $c);
    }

    public function testPermissionWithoutSecurityBundleIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->loadBundle(['dashboard' => ['permission' => 'ROLE_ADMIN']], environment: 'prod', bundles: []);
    }

    public function testEmptyPermissionIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->loadBundle(['dashboard' => ['permission' => '']], environment: 'prod');
    }

    public function testTheEasyAdminOptionIsOffByDefault(): void
    {
        $container = $this->loadBundle(['project_api_key' => 'key'], environment: 'prod');

        static::assertFalse($container->getParameter('jmonitor.easyadmin.enabled'));
        static::assertNull($container->getParameter('jmonitor.easyadmin.permission'));
        static::assertFalse($container->getParameter('jmonitor.easyadmin.permission_required'));
        static::assertSame([], $container->findTaggedServiceIds('ea.admin_route_controller'));

        $prefix = 'Jmonitor\\JmonitorBundle\\EasyAdmin\\';
        foreach ($container->getDefinitions() as $id => $definition) {
            static::assertStringStartsNotWith($prefix, $id);
            static::assertStringStartsNotWith($prefix, (string) $definition->getClass());
        }
    }

    /**
     * @return iterable<string, array{bool|array<string, mixed>, string|null, bool}>
     */
    public static function easyAdminActivationProvider(): iterable
    {
        yield 'true' => [true, null, false];
        yield 'enabled' => [['enabled' => true], null, false];
        yield 'a permission' => [['permission' => 'ROLE_X'], 'ROLE_X', true];
        yield 'a permission read from the environment' => [['permission' => '%env(default::JMONITOR_PERMISSION)%'], '%env(default::JMONITOR_PERMISSION)%', true];
    }

    /**
     * @param bool|array<string, mixed> $easyadmin
     */
    #[Group('easyadmin')]
    #[DataProvider('easyAdminActivationProvider')]
    public function testTheEasyAdminOptionIsTurnedOnByTrueOrByAPermission(bool|array $easyadmin, ?string $permission, bool $required): void
    {
        $container = $this->loadBundle(['easyadmin' => $easyadmin], bundles: $this->easyAdminBundles());

        static::assertTrue($container->getParameter('jmonitor.easyadmin.enabled'));
        static::assertSame($required, $container->getParameter('jmonitor.easyadmin.permission_required'));
        static::assertSame($permission, $container->getParameter('jmonitor.easyadmin.permission'));
    }

    public function testTheEasyAdminOptionWithoutEasyAdminIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "jmonitor.easyadmin" option requires the EasyAdminBundle: install easycorp/easyadmin-bundle 5.10.2 or later, or turn the option off.');

        $this->loadBundle(['easyadmin' => true], bundles: []);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function easyAdminOptionsFromTheEnvironment(): iterable
    {
        yield 'enabled' => [['enabled' => '%env(bool:JMONITOR_EASYADMIN)%']];
        yield 'the option itself' => ['%env(bool:JMONITOR_EASYADMIN)%'];
    }

    #[DataProvider('easyAdminOptionsFromTheEnvironment')]
    public function testTheEasyAdminOptionCannotBeReadFromTheEnvironment(mixed $easyadmin): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "jmonitor.easyadmin" option cannot be read from an environment variable: its routes are fixed when the container is built.');

        $this->loadBundle(['easyadmin' => $easyadmin], bundles: $this->easyAdminBundles());
    }

    #[DataProvider('easyAdminOptionsFromTheEnvironment')]
    public function testAKernelRefusesTheEasyAdminOptionReadFromTheEnvironment(mixed $easyadmin): void
    {
        $kernel = new TestKernel('test', true, ['easyadmin' => $easyadmin], withDoctrine: false, withHttpClient: false);

        try {
            $kernel->boot();
            static::fail('The kernel booted.');
        } catch (\LogicException $exception) {
            static::assertSame('The "jmonitor.easyadmin" option cannot be read from an environment variable: its routes are fixed when the container is built.', $exception->getMessage());
        } finally {
            (new Filesystem())->remove($kernel->getVarDir());
        }
    }

    #[Group('easyadmin')]
    public function testAPermissionReadFromTheEnvironmentIsAccepted(): void
    {
        $container = $this->loadBundle(['easyadmin' => ['enabled' => true, 'permission' => '%env(JMONITOR_PERMISSION)%']], bundles: $this->easyAdminBundles());

        static::assertTrue($container->getParameter('jmonitor.easyadmin.enabled'));
        static::assertTrue($container->getParameter('jmonitor.easyadmin.permission_required'));
    }

    public function testTheEasyAdminOptionCannotBeUsedWithJmonitorEasyadminBundle(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "jmonitor.easyadmin" option cannot be used together with jmonitor/easyadmin-bundle, which declares the same routes: remove that package, or turn the option off.');

        $this->loadBundle(['easyadmin' => true], bundles: ['JmonitorEasyAdminBundle' => 'Jmonitor\\EasyAdminBundle\\JmonitorEasyAdminBundle', ...$this->easyAdminBundles()]);
    }

    #[Group('easyadmin')]
    public function testAnEmptyEasyAdminPermissionIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "jmonitor.easyadmin.permission" option must not be empty.');

        $this->loadBundle(['easyadmin' => ['permission' => '']], bundles: $this->easyAdminBundles());
    }

    #[Group('easyadmin')]
    public function testAnEasyAdminPermissionWithoutSecurityBundleIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "jmonitor.easyadmin.permission" option requires the SecurityBundle.');

        $this->loadBundle(['easyadmin' => ['permission' => 'ROLE_X']], bundles: ['EasyAdminBundle' => EasyAdminBundle::class]);
    }

    #[Group('easyadmin')]
    public function testTheEasyAdminOptionRegistersALazyController(): void
    {
        $container = $this->loadBundle(['easyadmin' => ['permission' => 'ROLE_X']], bundles: $this->easyAdminBundles());

        static::assertTrue($container->hasDefinition(JmonitorController::class));
        $definition = $container->getDefinition(JmonitorController::class);
        static::assertTrue($definition->hasTag('controller.service_arguments'));
        static::assertTrue($definition->hasTag('ea.admin_route_controller'));
        static::assertFalse($definition->isPublic());

        $arguments = array_values($definition->getArguments());
        static::assertCount(2, $arguments);
        static::assertInstanceOf(Reference::class, $arguments[0]);
        static::assertSame(JmonitorPage::class, (string) $arguments[0]);
        static::assertInstanceOf(ServiceLocatorArgument::class, $arguments[1]);
        $locator = $arguments[1]->getValues();
        static::assertEqualsCanonicalizing(['twig', 'router', Access::class], array_keys($locator));
        static::assertSame(Access::class, (string) $locator[Access::class]);

        $access = $container->getDefinition(Access::class);
        static::assertFalse($access->isPublic());
        static::assertSame(['%jmonitor.easyadmin.permission_required%', '%jmonitor.easyadmin.permission%'], \array_slice($access->getArguments(), 0, 2));
        static::assertSame('security.authorization_checker', (string) $access->getArgument(2));
    }

    public function testAPermissionIsIgnoredWhileTheOptionIsOff(): void
    {
        $container = $this->loadBundle(['easyadmin' => ['enabled' => false, 'permission' => '']], bundles: []);

        static::assertFalse($container->getParameter('jmonitor.easyadmin.enabled'));
        static::assertNull($container->getParameter('jmonitor.easyadmin.permission'));
        static::assertFalse($container->getParameter('jmonitor.easyadmin.permission_required'));
    }

    /**
     * @return array<string, class-string>
     */
    private function easyAdminBundles(): array
    {
        return ['SecurityBundle' => SecurityBundle::class, 'EasyAdminBundle' => EasyAdminBundle::class];
    }

    public function testFlushIntervalIsAtMostOneHour(): void
    {
        static::assertSame(3600, $this->loadBundle(['recording' => ['flush_interval' => 3600]])->getParameter('jmonitor.recording.flush_interval'));

        $this->expectException(InvalidConfigurationException::class);
        $this->loadBundle(['recording' => ['flush_interval' => 3601]]);
    }

    private const SQL_SERVICES = [Middleware::class, QueryDigester::class, LabelStore::class, LabelSync::class];

    public function testSqlRecordingHasDefaults(): void
    {
        $container = $this->loadBundle([], environment: 'prod');

        static::assertTrue($container->getParameter('jmonitor.recording.sql.enabled'));
        static::assertSame(100, $container->getParameter('jmonitor.recording.sql.slow_threshold'));
        static::assertSame(10, $container->getParameter('jmonitor.recording.sql.n_plus_one_threshold'));
        foreach (self::SQL_SERVICES as $id) {
            static::assertTrue($container->hasDefinition($id), $id);
        }
        static::assertSame([[]], $container->getDefinition(Middleware::class)->getTag('doctrine.middleware'));
        static::assertSame(Recorder::class, (string) $container->getDefinition(Middleware::class)->getArgument(0));
        static::assertSame(QueryDigester::class, (string) $container->getDefinition(Recorder::class)->getArgument(3));
        static::assertSame(LabelSync::class, (string) $container->getDefinition(Flusher::class)->getArgument('$labels'));
        static::assertSame(Recorder::class, (string) $container->getDefinition(Flusher::class)->getArgument('$recorder'));
    }

    public function testSqlRecordingTakesTheProvidedValues(): void
    {
        $container = $this->loadBundle(['recording' => ['sql' => ['slow_threshold' => 250, 'n_plus_one_threshold' => 3]]], environment: 'prod');

        static::assertSame(250, $container->getParameter('jmonitor.recording.sql.slow_threshold'));
        static::assertSame(3, $container->getParameter('jmonitor.recording.sql.n_plus_one_threshold'));
        static::assertSame('%jmonitor.recording.sql.slow_threshold%', (string) $container->getDefinition(Recorder::class)->getArgument(4));
        static::assertSame('%jmonitor.recording.sql.n_plus_one_threshold%', (string) $container->getDefinition(QueryDigester::class)->getArgument(1));
    }

    public function testSqlRecordingCanBeSwitchedOff(): void
    {
        $container = $this->loadBundle(['recording' => ['sql' => ['enabled' => false]]], environment: 'prod');

        static::assertFalse($container->getParameter('jmonitor.recording.sql.enabled'));
        static::assertTrue($container->hasDefinition(Recorder::class));
        foreach (self::SQL_SERVICES as $id) {
            static::assertFalse($container->hasDefinition($id), $id);
        }
        static::assertFalse($container->getDefinition(Flusher::class)->getArguments()['$labels'] ?? false);
    }

    public function testNoSqlServiceWhenRecordingIsOff(): void
    {
        $container = $this->loadBundle(['recording' => ['enabled' => false]], environment: 'prod');

        foreach (self::SQL_SERVICES as $id) {
            static::assertFalse($container->hasDefinition($id), $id);
        }
    }

    public function testTheScreensAndThePublicServiceExistWhileThePageIsOff(): void
    {
        $container = $this->loadBundle(['dashboard' => ['enabled' => false]], environment: 'prod');

        foreach ([ScreenRegistry::class, ScreenBuilder::class, PageRenderer::class, JmonitorPage::class] as $id) {
            static::assertTrue($container->hasDefinition($id), $id);
        }
        static::assertTrue($container->getDefinition(JmonitorPage::class)->isPublic());
    }

    public function testTheContainerCompilesWithoutARouter(): void
    {
        $container = $this->loadBundle([], environment: 'prod', bundles: []);
        (new JmonitorBundle())->build($container);

        $container->compile();

        static::assertFalse($container->has('router'));
        static::assertInstanceOf(JmonitorPage::class, $container->get(JmonitorPage::class));
    }

    public function testTheReaderAndTheClockExistWhileRecordingAndThePageAreOff(): void
    {
        $container = $this->loadBundle(['recording' => ['enabled' => false], 'dashboard' => ['enabled' => false]], environment: 'prod');

        static::assertTrue($container->has(ReaderInterface::class));
        static::assertTrue($container->hasDefinition('jmonitor.clock'));
        foreach ([Recorder::class, RequestRecorder::class, CommandRecorder::class, MessengerRecorder::class, ExceptionRecorder::class, UserRecorder::class, CallRecorder::class] as $id) {
            static::assertFalse($container->hasDefinition($id), $id);
        }
    }

    public function testNoSqlMiddlewareWithoutDoctrineBundle(): void
    {
        $container = $this->loadBundle([], environment: 'prod', bundles: []);

        (new StorageConnectionPass())->process($container);

        static::assertFalse($container->hasDefinition(Middleware::class));
        static::assertFalse($container->hasDefinition(LabelSync::class));
    }

    public function testCommandRecordingHasDefaults(): void
    {
        $container = $this->loadBundle([], environment: 'prod');

        static::assertTrue($container->getParameter('jmonitor.recording.commands.enabled'));
        static::assertSame([], $container->getParameter('jmonitor.recording.commands.ignore'));

        $definition = $container->getDefinition(CommandRecorder::class);
        static::assertSame(Recorder::class, (string) $definition->getArgument(0));
        static::assertSame(Flusher::class, (string) $definition->getArgument(1));
        static::assertSame('%jmonitor.recording.commands.ignore%', $definition->getArgument(2));
        static::assertSame('%jmonitor.recording.exceptions%', $definition->getArgument(3));
        static::assertTrue($definition->hasTag('kernel.event_subscriber'));
        static::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'));
    }

    public function testCommandRecordingTakesTheProvidedPatterns(): void
    {
        $container = $this->loadBundle(['recording' => ['commands' => ['ignore' => ['app:internal:*']]]], environment: 'prod');

        static::assertTrue($container->getParameter('jmonitor.recording.commands.enabled'));
        static::assertSame(['app:internal:*'], $container->getParameter('jmonitor.recording.commands.ignore'));
    }

    public function testCommandRecordingCanBeSwitchedOff(): void
    {
        $container = $this->loadBundle(['recording' => ['commands' => false]], environment: 'prod');

        static::assertFalse($container->getParameter('jmonitor.recording.commands.enabled'));
        static::assertFalse($container->hasDefinition(CommandRecorder::class));
        static::assertTrue($container->hasDefinition(RequestRecorder::class));
    }

    public function testNoCommandRecorderWhenRecordingIsOff(): void
    {
        static::assertFalse($this->loadBundle(['recording' => ['enabled' => false]], environment: 'prod')->hasDefinition(CommandRecorder::class));
    }

    public function testMessengerRecordingHasDefaults(): void
    {
        $container = $this->loadBundle([], environment: 'prod');

        static::assertTrue($container->getParameter('jmonitor.recording.messenger'));
        static::assertTrue($container->getParameter('jmonitor.recording.scheduler'));

        $definition = $container->getDefinition(MessengerRecorder::class);
        static::assertSame(Recorder::class, (string) $definition->getArgument(0));
        static::assertSame(Flusher::class, (string) $definition->getArgument(1));
        static::assertSame('%jmonitor.recording.messenger%', $definition->getArgument(2));
        static::assertSame('%jmonitor.recording.scheduler%', $definition->getArgument(3));
        static::assertSame('%jmonitor.recording.exceptions%', $definition->getArgument(4));
        static::assertTrue($definition->hasTag('kernel.event_subscriber'));
        static::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'));
    }

    public function testMessengerRecorderStaysWhileMessagesOrTasksAreRecorded(): void
    {
        static::assertTrue($this->loadBundle(['recording' => ['messenger' => false]], environment: 'prod')->hasDefinition(MessengerRecorder::class));
        static::assertTrue($this->loadBundle(['recording' => ['scheduler' => false]], environment: 'prod')->hasDefinition(MessengerRecorder::class));
    }

    public function testNoMessengerRecorderWhenMessagesAndTasksAreOff(): void
    {
        $container = $this->loadBundle(['recording' => ['messenger' => false, 'scheduler' => false]], environment: 'prod');

        static::assertFalse($container->getParameter('jmonitor.recording.messenger'));
        static::assertFalse($container->getParameter('jmonitor.recording.scheduler'));
        static::assertFalse($container->hasDefinition(MessengerRecorder::class));
    }

    public function testNoMessengerRecorderWhenRecordingIsOff(): void
    {
        static::assertFalse($this->loadBundle(['recording' => ['enabled' => false]], environment: 'prod')->hasDefinition(MessengerRecorder::class));
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function invalidSqlThresholds(): iterable
    {
        yield 'slow threshold' => [['slow_threshold' => 0]];
        yield 'N+1 threshold' => [['n_plus_one_threshold' => 0]];
    }

    /**
     * @param array<string, int> $sql
     */
    #[DataProvider('invalidSqlThresholds')]
    public function testSqlThresholdsArePositive(array $sql): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->loadBundle(['recording' => ['sql' => $sql]]);
    }

    /**
     * @return array<class-string, int>
     */
    private static function loggingServices(): array
    {
        return [Flusher::class => 5, RequestRecorder::class => 3, CommandRecorder::class => 4, ScreenBuilder::class => 4];
    }

    public function testApplicationMonitoringUsesTheConfiguredLogger(): void
    {
        $container = $this->loadBundle(['logger' => 'app.jmonitor_logger'], environment: 'prod');

        foreach (self::loggingServices() as $id => $index) {
            $definition = $container->getDefinition($id);
            $logger = $definition->getArgument($index);

            static::assertInstanceOf(Reference::class, $logger, $id);
            static::assertSame('app.jmonitor_logger', (string) $logger, $id);
            static::assertFalse($definition->hasTag('monolog.logger'), $id);
        }
    }

    public function testApplicationMonitoringUsesTheJmonitorChannelByDefault(): void
    {
        $container = $this->loadBundle([], environment: 'prod');

        foreach (self::loggingServices() as $id => $index) {
            $definition = $container->getDefinition($id);
            $logger = $definition->getArgument($index);

            static::assertInstanceOf(Reference::class, $logger, $id);
            static::assertSame('logger', (string) $logger, $id);
            static::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $logger->getInvalidBehavior(), $id);
            static::assertSame([['channel' => 'jmonitor']], $definition->getTag('monolog.logger'), $id);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, class-string>|null, string, int}>
     */
    public static function recorderLoggers(): iterable
    {
        yield 'default logger' => [[], null, 'logger', ContainerInterface::NULL_ON_INVALID_REFERENCE];
        yield 'jmonitor channel of MonologBundle' => [[], ['MonologBundle' => 'Symfony\\Bundle\\MonologBundle\\MonologBundle'], 'monolog.logger.jmonitor', ContainerInterface::NULL_ON_INVALID_REFERENCE];
        yield 'configured logger' => [['logger' => 'app.jmonitor_logger'], null, 'app.jmonitor_logger', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE];
    }

    /**
     * @param array<string, mixed>              $config
     * @param array<string, class-string>|null $bundles
     */
    #[DataProvider('recorderLoggers')]
    public function testTheRecorderGetsItsLoggerOnlyWhenItLogs(array $config, ?array $bundles, string $logger, int $invalidBehavior): void
    {
        $container = $this->loadBundle($config, environment: 'prod', bundles: $bundles);

        $closure = $container->getDefinition(Recorder::class)->getArgument(1);

        static::assertInstanceOf(ServiceClosureArgument::class, $closure);
        $reference = $closure->getValues()[0];
        static::assertInstanceOf(Reference::class, $reference);
        static::assertSame($logger, (string) $reference);
        static::assertSame($invalidBehavior, $reference->getInvalidBehavior());
    }

    public function testMinimalConfigRegistersCoreServices(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'abc-123',
            // no collectors, no schedule
        ]);

        static::assertTrue($container->hasDefinition(Jmonitor::class), 'Jmonitor service should be defined');
        static::assertTrue(
            $container->hasDefinition(\Jmonitor\JmonitorBundle\Command\CollectorCommand::class),
            'CollectorCommand should be defined',
        );

        $jmonitorDef = $container->getDefinition(Jmonitor::class);
        $args = $jmonitorDef->getArguments();
        static::assertSame('abc-123', $args[0] ?? null);
        static::assertArrayHasKey(1, $args);
        static::assertNull($args[1], 'Default http_client argument should be null when not configured');

        $commandDef = $container->getDefinition(\Jmonitor\JmonitorBundle\Command\CollectorCommand::class);
        static::assertTrue($commandDef->hasTag('console.command'));
    }

    public function testJmonitorServiceDeclaresTheBundlePackage(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
        ]);

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $setBundleCalls = array_values(array_filter($calls, static fn(array $c) => 'setBundle' === $c[0]));

        static::assertCount(1, $setBundleCalls, 'Jmonitor should be told which bundle it runs behind');
        static::assertSame(['jmonitor/jmonitor-bundle'], $setBundleCalls[0][1]);
        static::assertTrue(
            method_exists(Jmonitor::class, 'setBundle'),
            'The installed collector should expose the setBundle hook',
        );
    }

    public function testMysqlCollectorsRegisterServicesAndMethodCalls(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition('jmonitor.doctrine_adapter.mysql'));
        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
        static::assertTrue($container->hasDefinition(MysqlVariablesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlSlowQueriesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlInformationSchemaCollector::class));

        $jmonitorDef = $container->getDefinition(Jmonitor::class);
        $calls = $jmonitorDef->getMethodCalls();
        $methods = array_map(static fn(array $c) => $c[0], $calls);

        static::assertContains('addCollector', $methods, 'addCollector should be called');

        // Ensure all mysql collectors are registered via addCollector calls
        $serializedArgs = array_map(static fn(array $c) => array_map(static fn($a) => (string) $a, $c[1]), $calls);
        $flat = array_merge(...$serializedArgs);

        static::assertContains(MysqlStatusCollector::class, $flat);
        static::assertContains(MysqlVariablesCollector::class, $flat);
    }

    public function testRedisCollectorMutualExclusivityValidation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'You cannot set both "dsn" and "adapter" for Redis collector. Please choose one.',
        );

        $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'redis' => [
                    'dsn' => 'redis://localhost',
                    'adapter' => 'some.redis.adapter',
                ],
            ],
        ]);
    }

    public function testRedisCollectorRegistersAndAddedToJmonitor(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'redis' => [
                    'dsn' => 'redis://localhost',
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(RedisCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));
        static::assertContains(RedisCollector::class, $flat);
    }

    public function testApacheCollectorRegistersAndAddedToJmonitor(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'apache' => [
                    'server_status_url' => 'http://localhost/server-status',
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(ApacheCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));
        static::assertContains(ApacheCollector::class, $flat);
    }

    public function testSystemCollectorRegistersWithCustomAdapter(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'system' => [
                    'adapter' => \stdClass::class,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(SystemCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));
        static::assertContains(SystemCollector::class, $flat);
    }

    public function testSymfonyCollectorRegistersWithAllComponents(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'flex' => true,
                    'scheduler' => true,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(SymfonyCollector::class));
        static::assertTrue($container->hasDefinition(SchedulerCollector::class));
        static::assertTrue($container->hasDefinition(FlexRecipesCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));
        static::assertContains(SymfonyCollector::class, $flat);
    }

    public function testSymfonyCollectorRegistersWithOnlyFlex(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'flex' => true,
                    'scheduler' => false,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(SymfonyCollector::class));
        static::assertFalse($container->hasDefinition(SchedulerCollector::class));
        static::assertTrue($container->hasDefinition(FlexRecipesCollector::class));
    }

    public function testSymfonyCollectorCanBeEnabledWithoutComponents(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => true,
            ],
        ]);

        static::assertTrue($container->hasDefinition(SymfonyCollector::class));

        if (class_exists('Symfony\Component\Scheduler\Scheduler')) {
            static::assertTrue(
                $container->hasDefinition(SchedulerCollector::class),
                'Scheduler should be enabled by default when symfony is true and component is present',
            );
        } else {
            static::assertFalse(
                $container->hasDefinition(SchedulerCollector::class),
                'Scheduler should be disabled by default when component is absent',
            );
        }

        if (class_exists('Symfony\Flex\SymfonyBundle')) {
            static::assertTrue(
                $container->hasDefinition(FlexRecipesCollector::class),
                'Flex should be enabled by default when symfony is true and component is present',
            );
        } else {
            static::assertFalse(
                $container->hasDefinition(FlexRecipesCollector::class),
                'Flex should be disabled by default when component is absent',
            );
        }
    }

    public function testSymfonyCollectorCanBeEnabledWithTilde(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => null,
            ],
        ]);

        static::assertTrue($container->hasDefinition(SymfonyCollector::class));

        if (class_exists('Symfony\Component\Scheduler\Scheduler')) {
            static::assertTrue($container->hasDefinition(SchedulerCollector::class));
        } else {
            static::assertFalse($container->hasDefinition(SchedulerCollector::class));
        }

        if (class_exists('Symfony\Flex\SymfonyBundle')) {
            static::assertTrue($container->hasDefinition(FlexRecipesCollector::class));
        } else {
            static::assertFalse($container->hasDefinition(FlexRecipesCollector::class));
        }
    }

    public function testApacheCollectorCanBeEnabledWithTilde(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'apache' => null,
            ],
        ]);

        static::assertTrue($container->hasDefinition(ApacheCollector::class));
    }

    public function testApacheCollectorCanBeDisabledExplicitly(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'apache' => [
                    'enabled' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(ApacheCollector::class));
    }

    public function testSymfonyCollectorWithCustomFlexCommand(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'flex' => [
                        'enabled' => true,
                        'command' => 'php bin/console custom:recipes',
                    ],
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(FlexRecipesCollector::class));
        static::assertSame(
            'php bin/console custom:recipes',
            $container->getDefinition(FlexRecipesCollector::class)->getArgument(1),
        );
    }

    public function testMysqlVariablesCollectorCanBeDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'variables' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MysqlVariablesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
    }

    public function testMysqlStatusCollectorCanBeDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'status' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MysqlStatusCollector::class));
        static::assertTrue($container->hasDefinition(MysqlVariablesCollector::class));
    }

    public function testMysqlSlowQueriesCollectorCanBeDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'slow_queries' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MysqlSlowQueriesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
    }

    public function testMysqlInformationSchemaCollectorCanBeDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'information_schema' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MysqlInformationSchemaCollector::class));
        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
    }

    public function testMysqlSubCollectorsEnabledWithExplicitNull(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'status' => null,
                    'variables' => null,
                    'slow_queries' => null,
                    'information_schema' => null,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
        static::assertTrue($container->hasDefinition(MysqlVariablesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlSlowQueriesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlInformationSchemaCollector::class));
    }

    public function testMysqlSlowQueriesCollectorWithCustomConfig(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'slow_queries' => [
                        'limit' => 5,
                        'min_exec_count' => 10,
                        'min_avg_time_ms' => 50,
                        'order_by' => 'avg',
                    ],
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MysqlSlowQueriesCollector::class));
        $def = $container->getDefinition(MysqlSlowQueriesCollector::class);
        static::assertSame('mydb', $def->getArgument(1));
        static::assertSame(5, $def->getArgument(2));
        static::assertSame(10, $def->getArgument(3));
        static::assertSame(50, $def->getArgument(4));
        static::assertSame('avg', $def->getArgument(5));
    }

    public function testMysqlSlowQueriesCollectorDefaultArgs(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'slow_queries' => null,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MysqlSlowQueriesCollector::class));
        $def = $container->getDefinition(MysqlSlowQueriesCollector::class);
        static::assertSame('mydb', $def->getArgument(1));
        static::assertSame(5, $def->getArgument(2));
        static::assertSame(1, $def->getArgument(3));
        static::assertSame(0, $def->getArgument(4));
        static::assertSame('avg', $def->getArgument(5));
    }

    public function testMysqlSlowQueriesInvalidOrderByThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid order_by value');

        $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'slow_queries' => [
                        'order_by' => 'invalid',
                    ],
                ],
            ],
        ]);
    }

    public function testMessengerCollectorRegistersWithBooleanTrue(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'messenger' => true,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MessengerStatsCollector::class));
        $def = $container->getDefinition(MessengerStatsCollector::class);
        static::assertNull($def->getArgument(1), 'Default command should be null');
        static::assertSame(3, $def->getArgument(2), 'Default timeout should be 3');
    }

    public function testMessengerCollectorNotRegisteredWithBooleanFalse(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'messenger' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MessengerStatsCollector::class));
    }

    public function testMessengerCollectorRegistersWithCustomCommandAndTimeout(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'messenger' => [
                        'enabled' => true,
                        'command' => '/usr/bin/php bin/console messenger:stats --format=json',
                        'timeout' => 10,
                    ],
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MessengerStatsCollector::class));
        $def = $container->getDefinition(MessengerStatsCollector::class);
        static::assertSame('/usr/bin/php bin/console messenger:stats --format=json', $def->getArgument(1));
        static::assertSame(10, $def->getArgument(2));
    }

    public function testMessengerCollectorRegistersWithDefaultsWhenEnabledExplicitly(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'messenger' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MessengerStatsCollector::class));
        $def = $container->getDefinition(MessengerStatsCollector::class);
        static::assertNull($def->getArgument(1));
        static::assertSame(3, $def->getArgument(2));
    }

    public function testDoctrineAdapterRegisteredEvenWhenAllSubCollectorsDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'status' => false,
                    'variables' => false,
                    'slow_queries' => false,
                    'information_schema' => false,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition('jmonitor.doctrine_adapter.mysql'));
        static::assertFalse($container->hasDefinition(MysqlStatusCollector::class));
        static::assertFalse($container->hasDefinition(MysqlVariablesCollector::class));
        static::assertFalse($container->hasDefinition(MysqlSlowQueriesCollector::class));
        static::assertFalse($container->hasDefinition(MysqlInformationSchemaCollector::class));
    }

    public function testPostgresqlCollectorsRegisterServicesAndMethodCalls(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'postgresql' => true,
            ],
        ]);

        static::assertTrue($container->hasDefinition('jmonitor.doctrine_adapter.postgresql'));
        static::assertTrue($container->hasDefinition(PostgresqlActivityCollector::class));
        static::assertTrue($container->hasDefinition(PostgresqlSettingsCollector::class));
        static::assertTrue($container->hasDefinition(PostgresqlDatabaseCollector::class));
        static::assertTrue($container->hasDefinition(PostgresqlSlowQueriesCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));

        static::assertContains(PostgresqlActivityCollector::class, $flat);
        static::assertContains(PostgresqlSettingsCollector::class, $flat);
        static::assertContains(PostgresqlDatabaseCollector::class, $flat);
        static::assertContains(PostgresqlSlowQueriesCollector::class, $flat);
    }

    public function testPostgresqlAdapterUsesConfiguredConnection(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'postgresql' => [
                    'connection' => 'doctrine.dbal.pgsql_connection',
                ],
            ],
        ]);

        $adapterDef = $container->getDefinition('jmonitor.doctrine_adapter.postgresql');
        static::assertSame(
            'doctrine.dbal.pgsql_connection',
            (string) $adapterDef->getArgument(0),
        );
    }

    public function testMysqlAdapterUsesConfiguredConnection(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'connection' => 'doctrine.dbal.mysql_connection',
                ],
            ],
        ]);

        $adapterDef = $container->getDefinition('jmonitor.doctrine_adapter.mysql');
        static::assertSame(
            'doctrine.dbal.mysql_connection',
            (string) $adapterDef->getArgument(0),
        );
    }

    public function testMysqlAndPostgresqlUseSeparateAdaptersWithTheirOwnConnections(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'connection' => 'doctrine.dbal.mysql_connection',
                ],
                'postgresql' => [
                    'connection' => 'doctrine.dbal.pgsql_connection',
                ],
            ],
        ]);

        $mysqlAdapter = $container->getDefinition('jmonitor.doctrine_adapter.mysql');
        $pgsqlAdapter = $container->getDefinition('jmonitor.doctrine_adapter.postgresql');

        static::assertSame('doctrine.dbal.mysql_connection', (string) $mysqlAdapter->getArgument(0));
        static::assertSame('doctrine.dbal.pgsql_connection', (string) $pgsqlAdapter->getArgument(0));
    }

    public function testPostgresqlSubCollectorCanBeDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'postgresql' => [
                    'slow_queries' => false,
                    'activity' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(PostgresqlSlowQueriesCollector::class));
        static::assertFalse($container->hasDefinition(PostgresqlActivityCollector::class));
        static::assertTrue($container->hasDefinition(PostgresqlSettingsCollector::class));
        static::assertTrue($container->hasDefinition(PostgresqlDatabaseCollector::class));
    }

    public function testPostgresqlDatabaseCollectorUsesConfiguredSchema(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'postgresql' => [
                    'schema' => 'analytics',
                ],
            ],
        ]);

        $def = $container->getDefinition(PostgresqlDatabaseCollector::class);
        static::assertSame('analytics', $def->getArgument(1));
    }

    public function testPostgresqlSlowQueriesCollectorWithCustomConfig(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'postgresql' => [
                    'slow_queries' => [
                        'limit' => 8,
                        'min_exec_count' => 20,
                        'min_avg_time_ms' => 12.5,
                        'order_by' => 'total',
                        'auto_create_extension' => true,
                    ],
                ],
            ],
        ]);

        $def = $container->getDefinition(PostgresqlSlowQueriesCollector::class);
        static::assertSame(8, $def->getArgument(1));
        static::assertSame(20, $def->getArgument(2));
        static::assertEquals(12.5, $def->getArgument(3));
        static::assertSame('total', $def->getArgument(4));
        static::assertTrue($def->getArgument(5));
    }

    public function testPostgresqlSlowQueriesCollectorDefaultArgs(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'postgresql' => [
                    'slow_queries' => null,
                ],
            ],
        ]);

        $def = $container->getDefinition(PostgresqlSlowQueriesCollector::class);
        static::assertSame(5, $def->getArgument(1));
        static::assertSame(1, $def->getArgument(2));
        static::assertEquals(0, $def->getArgument(3));
        static::assertSame('avg', $def->getArgument(4));
        static::assertFalse($def->getArgument(5));
    }

    public function testPostgresqlSlowQueriesInvalidOrderByThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid order_by value');

        $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'postgresql' => [
                    'slow_queries' => [
                        'order_by' => 'invalid',
                    ],
                ],
            ],
        ]);
    }

    public function testPostgresqlCanBeDisabledExplicitly(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'postgresql' => [
                    'enabled' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition('jmonitor.doctrine_adapter.postgresql'));
        static::assertFalse($container->hasDefinition(PostgresqlActivityCollector::class));
    }

    public function testCloudflareCollectorNotRegisteredWhenAbsent(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
        ]);

        static::assertFalse($container->hasDefinition(CloudflareCollector::class));
    }

    public function testCloudflareCollectorCanBeDisabledWithoutCredentials(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'cloudflare' => [
                    'enabled' => false,
                    'api_token' => '',
                    'zone' => null,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(CloudflareCollector::class));
    }

    public function testCloudflareCollectorRegistersWithDefaults(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'cloudflare' => [
                    'api_token' => 't',
                    'zone' => 'example.com',
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(CloudflareCollector::class));

        $def = $container->getDefinition(CloudflareCollector::class);
        static::assertSame([['name' => 'cloudflare']], $def->getTag('jmonitor.collector'));
        static::assertSame([
            't',
            'example.com',
            null,
            [
                'refresh' => [
                    'traffic' => 60,
                    'today' => 300,
                    'web_analytics' => 300,
                    'storage' => 900,
                    'zone' => 3600,
                ],
                'lag' => 180,
                'lookback' => 5,
                'window' => 15,
            ],
        ], $def->getArguments());

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));
        static::assertContains(CloudflareCollector::class, $flat);
    }

    public function testCloudflareCollectorUsesBundleHttpClient(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'http_client' => 'my_client',
            'collectors' => [
                'cloudflare' => [
                    'api_token' => 't',
                    'zone' => 'example.com',
                ],
            ],
        ]);

        $client = $container->getDefinition(CloudflareCollector::class)->getArgument(2);
        static::assertInstanceOf(Reference::class, $client);
        static::assertSame('my_client', (string) $client);
    }

    public function testCloudflareCollectorOwnHttpClientWinsOverBundleOne(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'http_client' => 'my_client',
            'collectors' => [
                'cloudflare' => [
                    'api_token' => 't',
                    'zone' => 'example.com',
                    'http_client' => 'cf_client',
                ],
            ],
        ]);

        $client = $container->getDefinition(CloudflareCollector::class)->getArgument(2);
        static::assertInstanceOf(Reference::class, $client);
        static::assertSame('cf_client', (string) $client);
    }

    public function testCloudflareCollectorPassesCustomOptions(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'cloudflare' => [
                    'api_token' => 't',
                    'zone' => 'example.com',
                    'refresh' => ['traffic' => 30],
                    'lookback' => 10,
                ],
            ],
        ]);

        $options = $container->getDefinition(CloudflareCollector::class)->getArgument(3);
        static::assertSame(30, $options['refresh']['traffic']);
        static::assertSame(300, $options['refresh']['today']);
        static::assertSame(10, $options['lookback']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null, string}>
     */
    public static function invalidCloudflareConfigProvider(): iterable
    {
        $credentialsMessage = 'The "api_token" and "zone" options must not be empty to enable the cloudflare collector.';

        yield 'empty api_token' => [['api_token' => '', 'zone' => 'example.com'], $credentialsMessage];
        yield 'blank api_token' => [['api_token' => " \n", 'zone' => 'example.com'], $credentialsMessage];
        yield 'missing zone' => [['api_token' => 't'], $credentialsMessage];
        yield 'enabled without credentials' => [null, $credentialsMessage];
        yield 'lookback above 60' => [['api_token' => 't', 'zone' => 'example.com', 'lookback' => 61], '"jmonitor.collectors.cloudflare.lookback"'];
        yield 'non-positive refresh' => [['api_token' => 't', 'zone' => 'example.com', 'refresh' => ['traffic' => 0]], '"jmonitor.collectors.cloudflare.refresh.traffic"'];
        yield 'non-positive lag' => [['api_token' => 't', 'zone' => 'example.com', 'lag' => 0], '"jmonitor.collectors.cloudflare.lag"'];
        yield 'unknown refresh key' => [['api_token' => 't', 'zone' => 'example.com', 'refresh' => ['dns' => 60]], '"dns" under "jmonitor.collectors.cloudflare.refresh"'];
    }

    /**
     * @param array<string, mixed>|null $cloudflare
     */
    #[DataProvider('invalidCloudflareConfigProvider')]
    public function testCloudflareCollectorRejectsInvalidConfig(?array $cloudflare, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'cloudflare' => $cloudflare,
            ],
        ]);
    }

    public function testTheStackIsOnByDefault(): void
    {
        $container = $this->loadBundle([], bundles: ['FrameworkBundle' => FrameworkBundle::class]);

        static::assertTrue($container->getParameter('jmonitor.stack.enabled'));
        static::assertTrue($container->getParameter('jmonitor.stack.end_of_life'));
        static::assertTrue($container->getParameter('jmonitor.stack.symfony.flex'));
        static::assertSame(
            ['system' => ['enabled' => 'auto'], 'php' => ['enabled' => 'auto'], 'symfony' => ['enabled' => 'auto']],
            $container->getParameter('jmonitor.stack.components'),
        );
        static::assertTrue($container->hasDefinition('jmonitor.stack.cache'));
    }

    public function testTheStackLoadsWithoutApiKeyAndWithoutDoctrine(): void
    {
        $container = $this->loadBundle([], bundles: ['FrameworkBundle' => FrameworkBundle::class]);

        static::assertTrue($container->hasDefinition('jmonitor.stack.cache'));
    }

    public function testTurningTheStackOffRegistersNothing(): void
    {
        $container = $this->loadBundle(['stack' => false], bundles: ['FrameworkBundle' => FrameworkBundle::class]);

        static::assertFalse($container->getParameter('jmonitor.stack.enabled'));
        foreach ($container->getDefinitions() as $id => $definition) {
            static::assertStringStartsNotWith('jmonitor.stack.', $id);
            static::assertStringStartsNotWith('Jmonitor\\JmonitorBundle\\Stack\\', $id);
            static::assertStringStartsNotWith('Jmonitor\\JmonitorBundle\\Stack\\', (string) $definition->getClass());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, array{enabled: 'auto'|bool}>, bool}>
     */
    public static function stackComponentProvider(): iterable
    {
        $auto = ['system' => ['enabled' => 'auto'], 'php' => ['enabled' => 'auto'], 'symfony' => ['enabled' => 'auto']];

        yield 'system: false' => [['system' => false], array_replace($auto, ['system' => ['enabled' => false]]), true];
        yield 'php: true' => [['php' => true], array_replace($auto, ['php' => ['enabled' => true]]), true];
        yield 'symfony: false' => [['symfony' => false], array_replace($auto, ['symfony' => ['enabled' => false]]), true];
        yield 'symfony: { flex: false }' => [['symfony' => ['flex' => false]], $auto, false];
        yield 'symfony: { enabled: true }' => [['symfony' => ['enabled' => true]], array_replace($auto, ['symfony' => ['enabled' => true]]), true];
    }

    /**
     * @param array<string, mixed>                       $stack
     * @param array<string, array{enabled: 'auto'|bool}> $components
     */
    #[DataProvider('stackComponentProvider')]
    public function testAComponentTakesAutoTrueOrFalse(array $stack, array $components, bool $flex): void
    {
        $container = $this->loadBundle(['stack' => $stack]);

        static::assertSame($components, $container->getParameter('jmonitor.stack.components'));
        static::assertSame($flex, $container->getParameter('jmonitor.stack.symfony.flex'));
    }

    public function testAnUnknownComponentSettingIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"jmonitor.stack.system"');

        $this->loadBundle(['stack' => ['system' => 'maybe']]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function stackOptionsFromTheEnvironment(): iterable
    {
        yield 'the option itself' => ['%env(bool:JMONITOR_STACK)%'];
        yield 'enabled' => [['enabled' => '%env(bool:JMONITOR_STACK)%']];
    }

    #[DataProvider('stackOptionsFromTheEnvironment')]
    public function testTheStackOptionCannotComeFromAnEnvironmentVariable(mixed $stack): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "jmonitor.stack" option cannot be read from an environment variable: its services are registered when the container is built.');

        $this->loadBundle(['stack' => $stack]);
    }

    public function testTheStackWiresItsDetectorsLazily(): void
    {
        $container = $this->loadBundle([], bundles: ['FrameworkBundle' => FrameworkBundle::class]);

        $detectors = $container->findTaggedServiceIds('jmonitor.stack.detector');
        static::assertEqualsCanonicalizing([SystemDetector::class, PhpDetector::class, SymfonyDetector::class], array_keys($detectors));
        static::assertSame([['index' => 'system']], $detectors[SystemDetector::class]);
        static::assertSame([['index' => 'php']], $detectors[PhpDetector::class]);
        static::assertSame([['index' => 'symfony']], $detectors[SymfonyDetector::class]);

        $registry = $container->getDefinition(ComponentRegistry::class);
        static::assertFalse($registry->isPublic());
        $locator = $registry->getArgument(0);
        static::assertInstanceOf(ServiceLocatorArgument::class, $locator);
        static::assertSame('jmonitor.stack.detector', $locator->getTaggedIteratorArgument()?->getTag());
        static::assertSame('index', $locator->getTaggedIteratorArgument()->getIndexAttribute());
        static::assertSame('%jmonitor.stack.components%', $registry->getArgument(1));
        static::assertSame([['method' => 'reset']], $registry->getTag('kernel.reset'));
    }

    public function testTheStackIsReadThroughOnePrivateReader(): void
    {
        $container = $this->loadBundle([], bundles: ['FrameworkBundle' => FrameworkBundle::class]);

        $reader = $container->getDefinition(LocalStackReader::class);
        static::assertFalse($reader->isPublic());
        static::assertSame(
            [ComponentRegistry::class, SnapshotProvider::class, CacheProbe::class, 'jmonitor.stack.cache', 'jmonitor.clock', WebNode::class],
            array_map(strval(...), \array_slice($reader->getArguments(), 0, 6)),
        );
        static::assertSame(LocalStackReader::class, (string) $container->getAlias(StackReaderInterface::class));
        static::assertFalse($container->getAlias(StackReaderInterface::class)->isPublic());

        $off = $this->loadBundle(['stack' => false], bundles: ['FrameworkBundle' => FrameworkBundle::class]);

        static::assertFalse($off->hasDefinition(LocalStackReader::class));
        static::assertFalse($off->hasAlias(StackReaderInterface::class));
    }
}
