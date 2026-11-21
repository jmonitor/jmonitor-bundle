<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[Group('easyadmin')]
final class EasyAdminBackOfficeTest extends WebTestCase
{
    private const ADMIN = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test'];
    private const USER = ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'test'];

    /** @var list<string> */
    private static array $varDirs = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove(self::$varDirs);
        self::$varDirs = [];
    }

    public function testBothDashboardsRenderTheEasyAdminLayout(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/admin', server: self::ADMIN);
        static::assertResponseStatusCodeSame(200);
        static::assertCount(1, $crawler->filter('body.ea'));

        $crawler = $client->request('GET', '/staff', server: self::USER);
        static::assertResponseStatusCodeSame(200);
        static::assertCount(1, $crawler->filter('body.ea'));
    }

    public function testTheFirewallGuardsTheBackOffice(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin');
        static::assertResponseStatusCodeSame(401);

        $client->request('GET', '/admin', server: self::USER);
        static::assertResponseStatusCodeSame(403);
    }

    public function testTheKernelRefusesEasyAdminWithoutDoctrineOrTwig(): void
    {
        foreach (['doctrine', 'twig'] as $missing) {
            try {
                static::createKernel([$missing => false]);
                static::fail(\sprintf('A kernel with EasyAdmin and without %s was created.', $missing));
            } catch (\LogicException $exception) {
                static::assertStringContainsString($missing === 'doctrine' ? 'Doctrine' : 'Twig', $exception->getMessage());
            }
        }
    }

    /**
     * @param array{environment?: string, debug?: bool, jmonitor?: array<string, mixed>, doctrine?: bool, twig?: bool, easyadmin?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
            $options['jmonitor'] ?? [],
            withDoctrine: $options['doctrine'] ?? true,
            withTwig: $options['twig'] ?? true,
            withEasyAdmin: $options['easyadmin'] ?? true,
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}
