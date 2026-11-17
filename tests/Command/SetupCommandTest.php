<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Jmonitor\JmonitorBundle\Command\SetupCommand;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\ConnectionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandTest extends TestCase
{
    /** @return array<string, array{Connection}> */
    public static function connections(): array
    {
        return ConnectionProvider::all();
    }

    #[DataProvider('connections')]
    public function testCreatesTheTableThenFindsIt(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS ' . AggregatesTable::NAME);

        $first = $this->execute($connection);

        static::assertSame(Command::SUCCESS, $first->getStatusCode());
        static::assertStringContainsString('Created the jmonitor_aggregates table in the "default" connection.', $this->display($first));
        static::assertTrue($connection->createSchemaManager()->tablesExist([AggregatesTable::NAME]));

        $second = $this->execute($connection);

        static::assertSame(Command::SUCCESS, $second->getStatusCode());
        static::assertStringContainsString('The jmonitor_aggregates table already exists in the "default" connection.', $this->display($second));
    }

    public function testUnsupportedPlatformFailsWithAClearMessage(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => SqlServerConnection::class]);

        $tester = $this->execute($connection);

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('Could not set up the storage in the "default" connection: Unsupported database platform', $this->display($tester));
        static::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name = 'jmonitor_aggregates'"));
    }

    private function execute(Connection $connection): CommandTester
    {
        $tester = new CommandTester(new SetupCommand(new DbalStorage($connection, 604800), 'default'));
        $tester->execute([]);

        return $tester;
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}

final class SqlServerConnection extends Connection
{
    public function getDatabasePlatform(): AbstractPlatform
    {
        return new SQLServerPlatform();
    }
}
