<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Recording\CommandRecorder;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class CommandRecordingTest extends KernelTestCase
{
    use PollsThePage;

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-command-recording-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testACommandIsStoredAsSoonAsItEnds(): void
    {
        $tester = $this->console();

        static::assertSame(0, $tester->run(['command' => 'app:work']));

        $command = self::rowKeyed($this->read(Family::Command), 'app:work');
        static::assertSame(1, $command->count(Metric::COMMAND_DURATION));
        static::assertSame(1.0, $command->sum(Metric::COMMAND_SQL_COUNT));
        static::assertSame([], glob($this->bufferDir . '/*/*.ndjson'));
    }

    public function testAFailingCommandCountsAFailureAndItsException(): void
    {
        $tester = $this->console();

        static::assertSame(1, $tester->run(['command' => 'app:work', '--fail' => true]));

        static::assertSame(1, self::rowKeyed($this->read(Family::Command), 'app:work')->count(Metric::COMMAND_FAILED));
        $where = self::rowKeyed($this->read(Family::ExceptionWhere), (new EntryPointKey(Entry::TYPE_COMMAND, 'app:work', 'RuntimeException'))->toString());
        static::assertSame(1, $where->count(Metric::EXCEPTION_WHERE_COUNT));
    }

    public function testAFailedCommandIsCriticalOnBackground(): void
    {
        $tester = $this->console();

        static::assertSame(1, $tester->run(['command' => 'app:work', '--fail' => true]));
        $client = static::getContainer()->get('test.client');
        \assert($client instanceof KernelBrowser);
        self::requestUntil($client, '/jmonitor/background', '[data-jm-check="commands.failed"]');
        $crawler = self::requestUntil($client, '/jmonitor/background', 'section#jm-commands tr[data-jm-command="app:work"][data-jm-runs="1"]');

        static::assertSame('1 command run in the last hour.', $crawler->filter('h1.jm-lead__title')->text());
        static::assertSame('1 command run failed.', $crawler->filter('.jm-lead__detail')->text());
        static::assertSame(['commands.failed'], $crawler->filter('[data-jm-check]')->each(static fn(Crawler $line): string => (string) $line->attr('data-jm-check')));
        $check = $crawler->filter('[data-jm-check="commands.failed"]');
        static::assertSame('bad', $check->attr('data-jm-severity'));
        static::assertSame('Critical', $check->filter('.jm-check__mark')->text());
        static::assertSame('1 command run failed · app:work ended with a non-zero exit code once.', $check->filter('.jm-check__sentence')->text());

        $card = $crawler->filter('.jm-screen > .jm-cards .jm-card');
        static::assertSame(['Commands', '1', 'run of 1 command, 1 failed'], [$card->filter('.jm-card__label')->text(), $card->filter('.jm-card__value')->text(), $card->filter('.jm-card__hint')->text()]);
        static::assertStringContainsString('jm-card--bad', (string) $card->attr('class'));
        $row = $crawler->filter('section#jm-commands tr[data-jm-command="app:work"]');
        static::assertSame(['1 failed'], $row->filter('.jm-tag--bad')->each(static fn(Crawler $tag): string => $tag->text()));
        static::assertCount(1, $row->filter('.jm-split'));
    }

    public function testARepeatedQueryOfACommandIsListedAsNPlusOne(): void
    {
        $tester = $this->console();

        static::assertSame(0, $tester->run(['command' => 'app:work', '--times' => '12']));

        $nPlusOne = $this->read(Family::NPlusOne)->rows;
        static::assertCount(1, $nPlusOne);
        $key = EntryPointKey::parse($nPlusOne[0]->key);
        static::assertNotNull($key);
        static::assertSame(Entry::TYPE_COMMAND, $key->unitType);
        static::assertSame('app:work', $key->unitKey);
        static::assertSame(SqlNormalizer::id((string) SqlNormalizer::normalize('SELECT 1', SqlDialect::Sqlite)), $key->subject);
    }

    public function testACommandRunFromAnotherIsStoredUnderItsOwnName(): void
    {
        $tester = $this->console();

        static::assertSame(0, $tester->run(['command' => 'app:work', '--then' => 'app:work', '--times' => '0']));

        $command = self::rowKeyed($this->read(Family::Command), 'app:work');
        static::assertSame(2, $command->count(Metric::COMMAND_DURATION));
        static::assertSame(2.0, $command->sum(Metric::COMMAND_SQL_COUNT));
    }

    public function testAnIgnoredCommandStoresNothing(): void
    {
        $tester = $this->console();

        static::assertSame(0, $tester->run(['command' => 'list']));

        static::assertSame([], glob($this->bufferDir . '/*/*.ndjson'));
        static::assertFalse($this->connection()->createSchemaManager()->tablesExist(['jmonitor_aggregates']));
    }

    public function testWithoutDoctrineTheConsoleStillRuns(): void
    {
        $tester = $this->console(withDoctrine: false);

        static::assertSame(0, $tester->run(['command' => 'app:work']));
        static::assertFalse(static::getContainer()->has(CommandRecorder::class));
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, doctrine?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, $options['jmonitor'] ?? [], withDoctrine: $options['doctrine'] ?? true);
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    private function console(bool $withDoctrine = true): ApplicationTester
    {
        $kernel = static::bootKernel([
            'jmonitor' => ['recording' => ['enabled' => true, 'buffer_dir' => $this->bufferDir]],
            'doctrine' => $withDoctrine,
        ]);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    private function read(Family $family): Report
    {
        $reader = static::getContainer()->get(ReaderInterface::class);
        \assert($reader instanceof ReaderInterface);

        return $reader->read(new ReportQuery(Period::OneHour, time(), $family, $family->weightMetric()));
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    private static function rowKeyed(Report $report, string $key): ReportRow
    {
        foreach ($report->rows as $row) {
            if ($row->key === $key) {
                return $row;
            }
        }

        static::fail(sprintf('No row has the key "%s".', $key));
    }
}
