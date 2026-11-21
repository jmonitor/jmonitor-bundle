<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\EasyAdmin\JmonitorController;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

#[Group('easyadmin')]
final class EasyAdminProofsTest extends WebTestCase
{
    use PollsThePage;

    private const ADMIN = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test'];
    private const USER = ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'test'];
    private const SUPER = ['PHP_AUTH_USER' => 'super', 'PHP_AUTH_PW' => 'test'];
    private const SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private const ROUTES = [
        'admin_jmonitor_index', 'admin_jmonitor_fragment', 'admin_jmonitor_action',
        'staff_jmonitor_index', 'staff_jmonitor_fragment', 'staff_jmonitor_action',
    ];

    private string $bufferDir;

    /** @var list<TestKernel> */
    private array $kernels = [];

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-easyadmin-proofs-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            self::$varDirs[] = $kernel->getVarDir();
        }
        $this->kernels = [];
        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testLoadingTheRoutesBuildsNoConnectionNoReaderNoTwig(): void
    {
        $withoutTheOption = $this->bootedTwice(false)->getContainer();
        $this->routesOf($withoutTheOption);

        $kernel = $this->bootedTwice(true);
        $container = $kernel->getContainer();
        $routes = $this->routesOf($container);

        foreach (self::ROUTES as $name) {
            static::assertNotNull($routes->get($name), $name);
        }
        static::assertTrue($container->initialized(JmonitorController::class));
        foreach ([PageRenderer::class, ScreenRegistry::class, ScreenBuilder::class, DbalReader::class, Flusher::class, 'doctrine.dbal.default_connection'] as $id) {
            static::assertFalse($container->initialized($id), $id);
        }
        static::assertSame($withoutTheOption->initialized('twig'), $container->initialized('twig'));

        $response = $kernel->handle(Request::create('/admin/jmonitor', server: self::ADMIN));

        static::assertSame(200, $response->getStatusCode());
        static::assertTrue($container->initialized(PageRenderer::class));
    }

    public function testTheRoutesCarryTheMark(): void
    {
        $this->client();
        $router = static::getContainer()->get('router');
        static::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();

        foreach (self::ROUTES as $name) {
            static::assertTrue($routes->get($name)?->getDefault('_jmonitor'), $name);
        }
    }

    public function testPagesFragmentsAndActionsAreNotRecorded(): void
    {
        $client = $this->client();

        $client->request('GET', '/hello/world');
        static::assertResponseIsSuccessful();
        foreach (['requests', 'queries', 'commands', 'cache', 'probe'] as $id) {
            $client->request('GET', '/admin/jmonitor/' . $id);
            static::assertResponseIsSuccessful($id);
            $client->request('GET', '/admin/jmonitor/_fragment/' . $id);
            static::assertResponseIsSuccessful($id);
        }
        $client->request('POST', '/admin/jmonitor/_action/probe', ['action' => 'ping'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);

        $crawler = $this->assertNoPageOfJmonitorIsRecorded($client, '/admin/jmonitor/requests', 2);
        static::assertSame(['GET app_hello'], $crawler->filter('tr[data-jm-route]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-route')));
    }

    public function testARequestRefusedByTheFirewallIsNotRecorded(): void
    {
        $client = $this->client();
        $client->setServerParameters([]);

        $client->request('GET', '/admin/jmonitor');
        static::assertResponseStatusCodeSame(401);
        $client->request('GET', '/admin/jmonitor', server: self::USER);
        static::assertResponseStatusCodeSame(403);

        $client->setServerParameters(self::ADMIN);
        $this->assertNoPageOfJmonitorIsRecorded($client, '/admin/jmonitor/requests');
    }

    public function testARequestRefusedByThePermissionIsNotRecorded(): void
    {
        $client = $this->client(['easyadmin' => ['permission' => 'ROLE_SUPER_ADMIN']]);

        $client->request('GET', '/admin/jmonitor');
        static::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/jmonitor/_fragment/probe');
        static::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/jmonitor/_action/probe', ['action' => 'ping'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(403);

        $client->setServerParameters(self::SUPER);
        $this->assertNoPageOfJmonitorIsRecorded($client, '/admin/jmonitor/requests');
    }

    public function testTheRestOfTheBackOfficeIsStillRecorded(): void
    {
        $client = $this->client();

        $client->request('GET', '/admin');
        static::assertResponseIsSuccessful();

        $crawler = self::requestUntil($client, '/admin/jmonitor/requests', 'tr[data-jm-route="GET admin"]');
        static::assertCount(1, $crawler->filter('tr[data-jm-route="GET admin"]'));
    }

    public function testWithTheOptionOffEasyAdminIsLeftAlone(): void
    {
        $client = $this->client(['easyadmin' => ['enabled' => false]]);
        $container = static::getContainer();

        $router = $container->get('router');
        static::assertInstanceOf(RouterInterface::class, $router);
        foreach (array_keys($router->getRouteCollection()->all()) as $name) {
            static::assertStringNotContainsString('_jmonitor_', $name);
            if (str_contains($name, 'jmonitor')) {
                static::assertStringStartsWith('jmonitor_', $name);
            }
        }
        static::assertFalse($container->has(JmonitorController::class));

        $client->request('GET', '/admin/jmonitor');
        static::assertResponseStatusCodeSame(404);
        $client->request('GET', '/admin');
        static::assertResponseStatusCodeSame(200);

        $twig = $container->get('twig');
        static::assertInstanceOf(Environment::class, $twig);
        static::assertFalse($twig->getLoader()->exists('@JmonitorEasyAdmin/page.html.twig'));

        $crawler = $client->request('GET', '/jmonitor/');
        static::assertResponseIsSuccessful();
        static::assertSame('JMonitor — Requests', $crawler->filter('title')->text());
        static::assertCount(1, $crawler->filter('.jm .jm-brand'));
    }

    private function assertNoPageOfJmonitorIsRecorded(KernelBrowser $client, string $requests, int $hellos = 1): Crawler
    {
        $client->request('GET', '/hello/world');
        static::assertResponseIsSuccessful();
        $row = \sprintf('tr[data-jm-route="GET app_hello"][data-jm-requests="%d"]', $hellos);
        $crawler = self::requestUntil($client, $requests, $row);

        static::assertCount(1, $crawler->filter($row));
        static::assertCount(0, $crawler->filter('tr[data-jm-route*="_jmonitor_"]'));

        return $crawler;
    }

    private function bootedTwice(bool $easyAdmin): TestKernel
    {
        $warmUp = $this->proofKernel($easyAdmin);
        $warmUp->boot();
        $warmUp->shutdown();

        $kernel = $this->proofKernel($easyAdmin);
        $this->kernels[] = $kernel;
        $kernel->boot();

        return $kernel;
    }

    private function proofKernel(bool $easyAdmin): TestKernel
    {
        return new TestKernel(
            'test',
            true,
            ['easyadmin' => ['enabled' => $easyAdmin], 'recording' => ['enabled' => true, 'buffer_dir' => $this->bufferDir]],
            withPublicInternals: true,
            withEasyAdmin: true,
        );
    }

    private function routesOf(ContainerInterface $container): RouteCollection
    {
        $router = $container->get('router');
        static::assertInstanceOf(RouterInterface::class, $router);

        return $router->getRouteCollection();
    }

    /**
     * @param array<string, mixed> $jmonitor
     */
    private function client(array $jmonitor = []): KernelBrowser
    {
        $client = static::createClient(['jmonitor' => array_replace_recursive(
            ['easyadmin' => ['enabled' => true], 'recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]],
            $jmonitor,
        )]);
        $client->setServerParameters(self::ADMIN);

        return $client;
    }

    /**
     * @param array{environment?: string, debug?: bool, jmonitor?: array<string, mixed>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
            $options['jmonitor'] ?? [],
            withEmbedHost: true,
            withEasyAdmin: true,
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}
