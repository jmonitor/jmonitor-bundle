<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\MessengerRecorder;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class MessengerRecordingTest extends WebTestCase
{
    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-messenger-recording-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testMessagesSentByARequestThenHandledByAWorker(): void
    {
        $client = static::createClient($this->options(messenger: true));

        $client->request('GET', '/dispatch');
        static::assertResponseIsSuccessful();
        static::assertSame(0, $this->consume(['receivers' => ['async'], '--limit' => '3']));

        $this->flush();
        $ping = self::rowKeyed($this->read(Family::Message), Ping::class);
        static::assertSame(3, $ping->count(Metric::MESSAGE_SENT));
        static::assertSame(3, $ping->count(Metric::MESSAGE_DURATION));
        static::assertSame(3.0, $ping->sum(Metric::MESSAGE_SQL_COUNT));
        static::assertTrue(static::getContainer()->has(MessengerRecorder::class));
    }

    public function testWithoutServiceResetsEveryMessageIsStillRecorded(): void
    {
        $client = static::createClient($this->options(messenger: true));

        $client->request('GET', '/dispatch');
        static::assertResponseIsSuccessful();
        static::assertSame(0, $this->consume(['receivers' => ['async'], '--limit' => '3', '--no-reset' => null]));

        $this->flush();
        $ping = self::rowKeyed($this->read(Family::Message), Ping::class);
        static::assertSame(3, $ping->count(Metric::MESSAGE_DURATION));
        static::assertSame(3.0, $ping->sum(Metric::MESSAGE_SQL_COUNT));
    }

    public function testAFailingMessageIsRetriedThenFails(): void
    {
        $client = static::createClient($this->options(messenger: true));

        $client->request('GET', '/dispatch', ['mode' => 'fail', 'count' => '1']);
        static::assertResponseIsSuccessful();
        static::assertSame(0, $this->consume(['receivers' => ['async'], '--limit' => '2']));

        $this->flush();
        $ping = self::rowKeyed($this->read(Family::Message), Ping::class);
        static::assertSame(1, $ping->count(Metric::MESSAGE_RETRIED));
        static::assertSame(1, $ping->count(Metric::MESSAGE_FAILED));
        static::assertSame(2, $ping->count(Metric::MESSAGE_DURATION));
        static::assertSame(2, self::rowKeyed($this->read(Family::Exception), 'DomainException')->count(Metric::EXCEPTION_COUNT));
        $where = self::rowKeyed($this->read(Family::ExceptionWhere), (new EntryPointKey(Entry::TYPE_MESSAGE, Ping::class, 'DomainException'))->toString());
        static::assertSame(2, $where->count(Metric::EXCEPTION_WHERE_COUNT));
    }

    public function testAMessageSentWhileHandlingAnotherIsCounted(): void
    {
        $client = static::createClient($this->options(messenger: true));

        $client->request('GET', '/dispatch', ['mode' => 'send', 'count' => '1']);
        static::assertResponseIsSuccessful();
        static::assertSame(0, $this->consume(['receivers' => ['async'], '--limit' => '2']));

        $this->flush();
        $ping = self::rowKeyed($this->read(Family::Message), Ping::class);
        static::assertSame(2, $ping->count(Metric::MESSAGE_SENT));
        static::assertSame(2, $ping->count(Metric::MESSAGE_DURATION));
    }

    public function testAScheduledCommandIsATaskAndACommand(): void
    {
        static::bootKernel($this->options(scheduler: true));

        static::assertSame(0, $this->consume(['receivers' => ['scheduler_default'], '--limit' => '1']));

        $this->flush();
        $tasks = array_values(array_filter(
            array_map(static fn(ReportRow $row): ?TaskKey => TaskKey::parse($row->key), $this->read(Family::Task)->rows),
            static fn(?TaskKey $key): bool => $key !== null && $key->schedule === 'default' && $key->label === 'app:work',
        ));
        static::assertCount(1, $tasks);
        static::assertSame(1, self::rowKeyed($this->read(Family::Command), 'app:work')->count(Metric::COMMAND_DURATION));
    }

    public function testWithoutMessengerEnabledNothingIsRegistered(): void
    {
        static::bootKernel($this->options());

        static::assertFalse(static::getContainer()->has(MessengerRecorder::class));
        static::assertFalse(static::getContainer()->has('messenger.receiver_locator'));
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, messenger?: bool, scheduler?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            'test',
            true,
            $options['jmonitor'] ?? [],
            withMessenger: $options['messenger'] ?? false,
            withScheduler: $options['scheduler'] ?? false,
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @return array{jmonitor: array<string, mixed>, messenger: bool, scheduler: bool}
     */
    private function options(bool $messenger = false, bool $scheduler = false): array
    {
        return [
            'jmonitor' => ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]],
            'messenger' => $messenger,
            'scheduler' => $scheduler,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function consume(array $input): int
    {
        $kernel = static::$kernel;
        \assert($kernel instanceof KernelInterface);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        return (new ApplicationTester($application))->run(
            ['command' => 'messenger:consume', '--time-limit' => '10', ...$input],
            ['interactive' => false],
        );
    }

    private function flush(): void
    {
        $flusher = static::getContainer()->get(Flusher::class);
        \assert($flusher instanceof Flusher);
        $deadline = microtime(true) + 10;

        do {
            static::assertTrue($flusher->flush());
            static::assertNull($flusher->lastFailure());
            if ((glob($this->bufferDir . '/*/*.ndjson') ?: []) === []) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        static::fail('The buffer was not drained within 10 s.');
    }

    private function read(Family $family): Report
    {
        $reader = static::getContainer()->get(ReaderInterface::class);
        \assert($reader instanceof ReaderInterface);

        return $reader->read(new ReportQuery(Period::OneHour, time(), $family, $family->weightMetric()));
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
