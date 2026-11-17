<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class SetupCommandRegistrationTest extends KernelTestCase
{
    /** @var list<string> */
    private static array $varDirs = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove(self::$varDirs);
        self::$varDirs = [];
    }

    public function testCreatesTheTableInTheConfiguredConnection(): void
    {
        $tester = new CommandTester((new Application(static::bootKernel()))->find('jmonitor:setup'));

        $tester->execute([]);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . AggregatesTable::NAME));
    }

    public function testDoesNotExistWithoutDoctrine(): void
    {
        $application = new Application(static::bootKernel(['doctrine' => false]));

        static::assertFalse($application->has('jmonitor:setup'));
        static::assertTrue($application->has('cache:clear'));
    }

    /**
     * @param array{doctrine?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, [], $options['doctrine'] ?? true);
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}
