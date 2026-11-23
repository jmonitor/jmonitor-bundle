<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

final class StackConnectionsTest extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            (new Filesystem())->remove($kernel->getVarDir());
        }
        $this->kernels = [];
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function databases(): iterable
    {
        yield 'sqlite' => ['default', null];
        yield 'mysql' => ['mysql', 'JMONITOR_TEST_MYSQL_URL'];
        yield 'postgresql' => ['postgresql', 'JMONITOR_TEST_PGSQL_URL'];
    }

    #[DataProvider('databases')]
    public function testTheTargetReadWithoutBuildingMatchesTheBuiltConnection(string $name, ?string $variable): void
    {
        if ($variable !== null && !\is_string(getenv($variable))) {
            self::markTestSkipped(\sprintf('%s is not set.', $variable));
        }

        $container = $this->container([
            'mysql' => ['url' => '%env(JMONITOR_TEST_MYSQL_URL)%', 'dbname_suffix' => '_suffix'],
            'postgresql' => ['url' => '%env(JMONITOR_TEST_PGSQL_URL)%', 'server_version' => '16'],
        ]);

        $target = self::connections($container)->target($name);
        $connection = $container->get('doctrine.dbal.' . $name . '_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $params = $connection->getParams();

        self::assertSame(
            [$params['host'] ?? null, $params['port'] ?? null, $params['dbname'] ?? null],
            [$target->host, $target->port, $target->database],
        );
        self::assertSame(['default' => null, 'mysql' => 'mysql', 'postgresql' => 'postgresql'][$name], $target->driver);
    }

    public function testAConnectionWhoseVariableIsMissingBreaksNeitherTheApplicationNorTheOthers(): void
    {
        self::assertFalse(getenv('NOPE_URL'));
        $kernel = $this->kernel(['broken' => ['url' => '%env(NOPE_URL)%']]);
        $kernel->boot();

        $response = $kernel->handle(Request::create('/hello/world'));
        $connections = self::connections($kernel->getContainer());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['default', 'broken'], $connections->names());
        self::assertSame('localhost', $connections->target('default')->host);
        $failure = Failures::thrownBy(static fn() => $connections->target('broken'));
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('The parameters of this connection cannot be read: an environment variable is missing ("NOPE_URL").', $failure->getMessage());
    }

    public function testReadingTheTargetsBuildsNoConnection(): void
    {
        $container = $this->container(['e' => ['url' => 'mysql://jmonitor:jmonitor@127.0.0.1:1/jmonitor', 'mapping_types' => ['enum' => 'string']]]);
        $connections = self::connections($container);

        foreach ($connections->names() as $name) {
            $connections->target($name);
        }

        self::assertSame(1, $connections->target('e')->port);
        self::assertFalse($container->initialized('doctrine.dbal.e_connection'));
        self::assertFalse($container->initialized('doctrine.dbal.default_connection'));
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     */
    private function container(array $connections): ContainerInterface
    {
        $warmUp = $this->kernel($connections);
        $warmUp->boot();
        $warmUp->shutdown();
        $kernel = $this->kernel($connections);
        $kernel->boot();

        return $kernel->getContainer();
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     */
    private function kernel(array $connections): TestKernel
    {
        return $this->kernels[] = new TestKernel('test', true, withPublicInternals: true, connections: $connections);
    }

    private static function connections(ContainerInterface $container): DoctrineConnections
    {
        $connections = $container->get(DoctrineConnections::class);
        self::assertInstanceOf(DoctrineConnections::class, $connections);

        return $connections;
    }
}
