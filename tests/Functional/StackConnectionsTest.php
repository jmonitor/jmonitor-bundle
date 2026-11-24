<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ConnectionTarget;
use Jmonitor\JmonitorBundle\Stack\Detection\DatabaseInstances;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
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
        self::assertSame(
            'No MySQL Doctrine connection found. Connection "broken" could not be read: The parameters of this connection cannot be read: an environment variable is missing ("NOPE_URL").',
            self::detect($kernel->getContainer())->error,
        );
    }

    public function testReadingTheTargetsBuildsNoConnection(): void
    {
        $container = $this->container(['e' => ['url' => 'mysql://jmonitor:jmonitor@127.0.0.1:1/jmonitor', 'mapping_types' => ['enum' => 'string']]]);
        $connections = self::connections($container);

        foreach ($connections->names() as $name) {
            $connections->target($name);
        }

        self::assertSame(1, $connections->target('e')->port);
        $detection = self::detect($container);
        self::assertSame('e', $detection->instances[0]->id);
        self::assertSame('127.0.0.1:1', $detection->instances[0]->server?->key);
        self::assertFalse($container->initialized('doctrine.dbal.e_connection'));
        self::assertFalse($container->initialized('doctrine.dbal.default_connection'));
    }

    public function testAConnectionTheLinkBuiltIsClosedOnRelease(): void
    {
        $container = $this->openingConnection();

        self::readAndRelease($container);

        $connection = $container->get('doctrine.dbal.e_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertFalse($connection->isConnected());
    }

    public function testAConnectionTheApplicationBuiltIsLeftOpen(): void
    {
        $container = $this->openingConnection();
        $connection = $container->get('doctrine.dbal.e_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertTrue($connection->isConnected());

        self::readAndRelease($container);

        self::assertTrue($connection->isConnected());
    }

    public function testWithoutDoctrineNoConnectionIsConfigured(): void
    {
        $kernel = $this->kernels[] = new TestKernel('test', true, withDoctrine: false, withPublicInternals: true);
        $kernel->boot();

        self::assertSame([], self::connections($kernel->getContainer())->names());
        self::assertSame('No Doctrine DBAL connection is configured.', self::detect($kernel->getContainer())->error);
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

    /**
     * A connection that its construction opens, to read the server version its mapping types need.
     */
    private function openingConnection(): ContainerInterface
    {
        if (!\is_string(getenv('JMONITOR_TEST_MYSQL_URL'))) {
            self::markTestSkipped('JMONITOR_TEST_MYSQL_URL is not set.');
        }

        $container = $this->container(['e' => ['url' => '%env(JMONITOR_TEST_MYSQL_URL)%', 'mapping_types' => ['enum' => 'string']]]);
        self::assertFalse($container->initialized('doctrine.dbal.e_connection'));

        return $container;
    }

    private static function readAndRelease(ContainerInterface $container): void
    {
        $instances = $container->get(DatabaseInstances::class);
        self::assertInstanceOf(DatabaseInstances::class, $instances);
        $links = [];
        $set = $instances->detect(Component::System, 'mysql', 'MySQL', ['e' => []], static function (ConnectionTarget $target, DbalLink $link) use (&$links): array {
            $links[] = $link;

            return [];
        }, true, 3306)->instances[0]->collectors;
        self::assertNotNull($set);

        self::assertEquals([['one' => 1]], $links[0]->fetchAllAssociative('SELECT 1 AS one'));
        $set->release();
    }

    private static function detect(ContainerInterface $container): Detection
    {
        $instances = $container->get(DatabaseInstances::class);
        self::assertInstanceOf(DatabaseInstances::class, $instances);

        return $instances->detect(Component::System, 'mysql', 'MySQL', [], static fn(): array => [], true, 3306);
    }

    private static function connections(ContainerInterface $container): DoctrineConnections
    {
        $connections = $container->get(DoctrineConnections::class);
        self::assertInstanceOf(DoctrineConnections::class, $connections);

        return $connections;
    }
}
