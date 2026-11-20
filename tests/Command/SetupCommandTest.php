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
use Jmonitor\JmonitorBundle\Storage\Dbal\KeysTable;
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
    public function testCreatesTheTablesThenFindsThem(Connection $connection): void
    {
        ConnectionProvider::dropTables($connection);

        $first = $this->execute($connection);

        static::assertSame(Command::SUCCESS, $first->getStatusCode());
        static::assertStringContainsString('Created the JMonitor tables in the "default" connection.', $this->display($first));
        static::assertTrue($connection->createSchemaManager()->tablesExist([AggregatesTable::NAME, KeysTable::NAME]));

        $second = $this->execute($connection);

        static::assertSame(Command::SUCCESS, $second->getStatusCode());
        static::assertStringContainsString('The JMonitor tables are up to date in the "default" connection.', $this->display($second));
    }

    #[DataProvider('connections')]
    public function testUpgradesTablesOfAnOlderSchema(Connection $connection): void
    {
        ConnectionProvider::dropTables($connection);
        $this->execute($connection);
        $connection->executeStatement("UPDATE jmonitor_keys SET metric_key = '1' WHERE key_hash = 'schema'");

        $tester = $this->execute($connection);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString('Upgraded the JMonitor tables in the "default" connection.', $this->display($tester));
        static::assertSame('3', $connection->fetchOne("SELECT metric_key FROM jmonitor_keys WHERE key_hash = 'schema'"));
    }

    #[DataProvider('connections')]
    public function testRecreatesAMissingAggregatesTable(Connection $connection): void
    {
        ConnectionProvider::dropTables($connection);
        $this->execute($connection);
        $connection->executeStatement('DROP TABLE ' . AggregatesTable::NAME);

        $tester = $this->execute($connection);

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString('Upgraded the JMonitor tables in the "default" connection.', $this->display($tester));
        static::assertTrue($connection->createSchemaManager()->tablesExist([AggregatesTable::NAME, KeysTable::NAME]));
    }

    public function testUnsupportedPlatformFailsWithAClearMessage(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => SqlServerConnection::class]);

        $tester = $this->execute($connection);

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('Could not set up the storage in the "default" connection: Unsupported database platform', $this->display($tester));
        static::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'jmonitor_%'"));
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
