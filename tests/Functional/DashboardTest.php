<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

final class DashboardTest extends WebTestCase
{
    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-dashboard-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testPageListsRecordedRoutes(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world');
        }
        sleep(1);
        $crawler = $client->request('GET', '/jmonitor/');
        $response = $client->getResponse();

        static::assertResponseIsSuccessful();
        static::assertSame('3', $crawler->filter('tr[data-jm-route="GET app_hello"]')->attr('data-jm-requests'));
        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        static::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testDashboardRoutesAreNotRecorded(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/jmonitor/');
            static::assertResponseIsSuccessful();
        }
        sleep(1);
        $crawler = $client->request('GET', '/jmonitor/');

        static::assertCount(1, $crawler->filter('tr[data-jm-route="GET app_hello"]'));
        static::assertCount(0, $crawler->filter('tr[data-jm-route^="GET jmonitor_"]'));
    }

    public function testFragmentReturnsOnlyTheContent(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        sleep(1);
        $client->request('GET', '/jmonitor/_fragment');
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertStringNotContainsString('<html', $html);
        static::assertStringContainsString('<table', $html);
        static::assertStringContainsString('data-jm-route="GET app_hello"', $html);
        static::assertSame('noindex', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testInvalidQueryParametersDoNotBreakThePage(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/?period=abc&sort=x&dir=%00');
        static::assertResponseIsSuccessful();

        $client->request('GET', '/jmonitor/_fragment?period[]=1h&sort[x]=y');
        static::assertResponseIsSuccessful();
    }

    public function testEmptyState(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/jmonitor/');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-jm-state="empty"]'));
        static::assertSame('JMonitor — Requests', $crawler->filter('title')->text());
    }

    public function testNoConnectionState(): void
    {
        $client = static::createClient(['doctrine' => false]);

        $crawler = $client->request('GET', '/jmonitor/');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-jm-state="no_connection"]'));
    }

    public function testNotFoundOutsideDebug(): void
    {
        $kernel = static::createKernel(['environment' => 'prod', 'debug' => false, 'jmonitor' => ['recording' => ['buffer_dir' => $this->bufferDir]]]);

        $response = $kernel->handle(Request::create('/jmonitor/'));
        $kernel->shutdown();

        static::assertSame(404, $response->getStatusCode());
    }

    public function testPermission(): void
    {
        $client = static::createClient(['debug' => false, 'security' => true, 'jmonitor' => ['dashboard' => ['permission' => 'ROLE_ADMIN']]]);

        $client->request('GET', '/jmonitor/');
        static::assertResponseStatusCodeSame(401);

        $client->request('GET', '/jmonitor/', server: ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'test']);
        static::assertResponseStatusCodeSame(403);

        $client->request('GET', '/jmonitor/', server: ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test']);
        static::assertResponseStatusCodeSame(200);
    }

    public function testStorageFailureIsShown(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        sleep(1);
        $client->disableReboot();
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->beginTransaction();

        $crawler = $client->request('GET', '/jmonitor/');
        $connection->rollBack();

        static::assertResponseStatusCodeSame(200);
        static::assertCount(1, $crawler->filter('[data-jm-state="storage_error"]'));
    }

    public function testAssetsAreReferencedWithTheBasePath(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/');
        $html = (string) $client->getResponse()->getContent();

        static::assertStringContainsString('"/bundles/jmonitor/jmonitor.css"', $html);
        static::assertStringContainsString('"/bundles/jmonitor/jmonitor.js"', $html);

        $client->request('GET', '/sub/index.php/jmonitor/', server: ['SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/srv/sub/index.php']);
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('"/sub/bundles/jmonitor/jmonitor.css"', $html);
        static::assertStringContainsString('"/sub/bundles/jmonitor/jmonitor.js"', $html);
    }

    /**
     * @param array{environment?: string, debug?: bool, jmonitor?: array<string, mixed>, doctrine?: bool, security?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
            $options['jmonitor'] ?? [],
            $options['doctrine'] ?? true,
            $options['security'] ?? false,
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @return array<string, mixed>
     */
    private function recording(): array
    {
        return ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]];
    }
}
