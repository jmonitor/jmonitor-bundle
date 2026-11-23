<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class QueuesCommandTest extends KernelTestCase
{
    private const COUNTING = ['end_of_life' => false, 'symfony' => ['flex' => false, 'messenger' => true]];

    /** @var list<string> */
    private static array $removed = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove(self::$removed);
        self::$removed = [];
    }

    public function testEachTransportOfTheApplicationIsCountedOnce(): void
    {
        $tester = $this->console(['stack' => self::COUNTING]);

        self::assertSame(0, $tester->run(['command' => 'jmonitor:stack:queues']));
        self::assertSame([
            '{"transports":["async","batch","counted"]}',
            '{"name":"async","count":null}',
            '{"name":"batch","count":null}',
            '{"name":"counted","count":12}',
        ], self::lines($tester));
        self::assertStringNotContainsString('messenger.transport.', $tester->getDisplay());
    }

    public function testASchedulerTransportIsNotEvenNamed(): void
    {
        $tester = $this->console(['stack' => self::COUNTING], withMessenger: false, withScheduler: true);

        self::assertSame(0, $tester->run(['command' => 'jmonitor:stack:queues']));
        self::assertSame('{"transports":["async","batch","counted"]}', self::lines($tester)[0]);
        self::assertStringNotContainsString('scheduler', $tester->getDisplay());
    }

    public function testTheCommandIsHidden(): void
    {
        $tester = $this->console(['stack' => self::COUNTING]);
        $application = new Application(static::$kernel);

        self::assertTrue($application->has('jmonitor:stack:queues'));
        self::assertTrue($application->find('jmonitor:stack:queues')->isHidden());
        self::assertSame(0, $tester->run(['command' => 'list']));
        self::assertStringNotContainsString('jmonitor:stack:queues', $tester->getDisplay());
    }

    public function testTheCommandIsNotRecordedAndWritesNothing(): void
    {
        $bufferDir = sys_get_temp_dir() . '/jmonitor-queues-recording-' . bin2hex(random_bytes(6));
        mkdir($bufferDir);
        self::$removed[] = $bufferDir;
        $tester = $this->console(['stack' => self::COUNTING, 'recording' => ['enabled' => true, 'buffer_dir' => $bufferDir]]);

        self::assertSame(0, $tester->run(['command' => 'jmonitor:stack:queues']));

        $recorder = static::getContainer()->get(Recorder::class);
        self::assertInstanceOf(Recorder::class, $recorder);
        self::assertFalse($recorder->hasWritten());
        self::assertSame([], glob($bufferDir . '/*/*.ndjson'));
    }

    public function testWithoutMessengerThereIsNoCommand(): void
    {
        $this->console(['stack' => self::COUNTING], withMessenger: false, transports: []);

        self::assertFalse((new Application(static::$kernel))->has('jmonitor:stack:queues'));
    }

    public function testWithTheCountingOffThereIsNoCommand(): void
    {
        $this->console(['stack' => ['end_of_life' => false, 'symfony' => ['flex' => false, 'messenger' => false]]]);

        self::assertFalse((new Application(static::$kernel))->has('jmonitor:stack:queues'));
    }

    public function testWithTheStackOffThereIsNoCommand(): void
    {
        $this->console(['stack' => false]);

        self::assertFalse((new Application(static::$kernel))->has('jmonitor:stack:queues'));
    }

    public function testTheKernelOfTheTestsAnswersInsteadOfTheConsole(): void
    {
        static::bootKernel(['jmonitor' => ['stack' => self::COUNTING], 'queues' => "{\"transports\":[\"async\"]}\n{\"name\":\"async\",\"count\":7}\n"]);
        $reader = static::getContainer()->get(StackReaderInterface::class);
        self::assertInstanceOf(StackReaderInterface::class, $reader);

        $reader->queues(ReadMode::Page);
        $state = $reader->queues(ReadMode::Fragment);

        $runner = static::getContainer()->get('jmonitor.tests.queues_runner');
        self::assertInstanceOf(FakeProcessRunner::class, $runner);
        self::assertCount(1, $runner->calls);
        self::assertSame(
            ['transports' => [['name' => 'async', 'count' => 7, 'reason' => null]], 'complete' => true],
            $state?->component(Component::Symfony)?->instances[0]->snapshot?->data('queues'),
        );
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, messenger?: bool, scheduler?: bool, transports?: array<string, string>, queues?: string} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            'test',
            true,
            $options['jmonitor'] ?? [],
            withMessenger: $options['messenger'] ?? true,
            withScheduler: $options['scheduler'] ?? false,
            transports: $options['transports'] ?? [],
            queuesOutput: $options['queues'] ?? null,
        );
        self::$removed[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @param array<string, mixed>  $jmonitor
     * @param array<string, string> $transports
     */
    private function console(array $jmonitor, bool $withMessenger = true, bool $withScheduler = false, array $transports = ['counted' => 'counted://12']): ApplicationTester
    {
        $kernel = static::bootKernel(['jmonitor' => $jmonitor, 'messenger' => $withMessenger, 'scheduler' => $withScheduler, 'transports' => $transports]);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    /**
     * @return list<string>
     */
    private static function lines(ApplicationTester $tester): array
    {
        return explode("\n", trim($tester->getDisplay()));
    }
}
