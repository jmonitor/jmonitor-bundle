<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpCli;
use Jmonitor\JmonitorBundle\Stack\Collector\QueuesCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class QueuesCollectorTest extends TestCase
{
    private const PHP = '/usr/local/bin/php';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/jmonitor-queues-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/bin', 0o777, true);
        touch($this->projectDir . '/bin/console');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testItStartsTheConsoleOfTheApplicationInItsEnvironment(): void
    {
        $runner = (new FakeProcessRunner())->answer(null, '{"transports":[]}');

        $prod = $this->collector($runner, environment: 'prod', debug: false);
        $dev = $this->collector($runner, environment: 'dev', debug: true);
        $prod->collect();
        $dev->collect();

        self::assertSame('queues', $prod->getName());
        self::assertSame([
            [
                'command' => [self::PHP, $this->projectDir . '/bin/console', 'jmonitor:stack:queues', '--env=prod', '--no-debug'],
                'working_directory' => $this->projectDir,
                'timeout' => 3,
                'env' => ['APP_ENV' => 'prod', 'APP_DEBUG' => '0'],
            ],
            [
                'command' => [self::PHP, $this->projectDir . '/bin/console', 'jmonitor:stack:queues', '--env=dev'],
                'working_directory' => $this->projectDir,
                'timeout' => 3,
                'env' => ['APP_ENV' => 'dev', 'APP_DEBUG' => '1'],
            ],
        ], $runner->calls);
    }

    public function testCountsAreReadFromTheLines(): void
    {
        $output = <<<'TXT'
            {"transports":["async","failed","reports","batch"]}
            {"name":"async","count":12}
            {"name":"failed","count":0}
            {"name":"reports","error":"Connection refused"}
            {"name":"batch","count":null}
            TXT;

        self::assertSame([
            'transports' => [
                ['name' => 'async', 'count' => 12, 'reason' => null],
                ['name' => 'failed', 'count' => 0, 'reason' => null],
                ['name' => 'reports', 'count' => null, 'reason' => 'Connection refused'],
                ['name' => 'batch', 'count' => null, 'reason' => 'this transport cannot count'],
            ],
        ], $this->collect($output));
    }

    public function testWhatIsWrittenAroundTheLinesIsIgnored(): void
    {
        $output = "Deprecated: Creation of dynamic property is deprecated in /srv/app/src/Kernel.php on line 12\n\n"
            . "{\"transports\":[\"async\",\"failed\"]}\n"
            . "\n"
            . "[\"async\",3]\n"
            . "{\"name\":\"async\",\"count\":-4}\n"
            . "{\"name\":\"failed\",\"count\":\"7\"}\n"
            . "{\"name\":\"async\",\"count\":5}\n"
            . "PHP Warning:  something\n";

        self::assertSame([
            'transports' => [
                ['name' => 'async', 'count' => null, 'reason' => 'this transport cannot count'],
                ['name' => 'failed', 'count' => null, 'reason' => 'this transport cannot count'],
            ],
        ], $this->collect($output), 'a negative or non-integer count is no count, the first answer of a transport is its answer');
    }

    public function testASkippedTransportIsNotListed(): void
    {
        $output = "{\"transports\":[\"scheduler_default\",\"now\",\"async\"]}\n"
            . "{\"name\":\"scheduler_default\",\"skip\":true}\n"
            . "{\"name\":\"now\",\"skip\":true}\n"
            . "{\"name\":\"async\",\"count\":2}\n";

        self::assertSame(['transports' => [['name' => 'async', 'count' => 2, 'reason' => null]]], $this->collect($output));
    }

    public function testATransportWithANumericNameKeepsItsName(): void
    {
        $output = "{\"transports\":[\"2024\",\"1\"]}\n{\"name\":\"2024\",\"count\":3}\n{\"name\":\"1\",\"count\":4}\n";

        self::assertSame([
            'transports' => [
                ['name' => '2024', 'count' => 3, 'reason' => null],
                ['name' => '1', 'count' => 4, 'reason' => null],
            ],
        ], $this->collect($output));
    }

    public function testATransportWithoutLineAfterATimeoutDidNotAnswerInTime(): void
    {
        $runner = (new FakeProcessRunner())->answer(null, "{\"transports\":[\"async\",\"reports\",\"batch\"]}\n{\"name\":\"async\",\"count\":1}\n", null, timedOut: true);

        self::assertSame([
            'transports' => [
                ['name' => 'async', 'count' => 1, 'reason' => null],
                ['name' => 'reports', 'count' => null, 'reason' => 'did not answer in time'],
                ['name' => 'batch', 'count' => null, 'reason' => 'not counted: an earlier transport did not answer in time'],
            ],
        ], $this->collector($runner)->collect(), 'the transports are counted one after the other: the first without a line is the one that hung');
    }

    public function testATransportWithoutLineOtherwiseGaveNoAnswer(): void
    {
        $runner = (new FakeProcessRunner())->answer(null, "{\"transports\":[\"async\"]}\n", 255, 'PHP Fatal error:  Allowed memory size exhausted');

        self::assertSame(
            ['transports' => [['name' => 'async', 'count' => null, 'reason' => 'no answer']]],
            $this->collector($runner)->collect(),
        );
    }

    public function testWithoutTheListOfTransportsItIsAFailure(): void
    {
        $runner = (new FakeProcessRunner())->answer(null, '<html>Fatal error</html>', 255, str_repeat('PHP Fatal error:  Uncaught Exception: Cannot reach redis://app:s3cret@cache ', 20));

        $e = Failures::thrownBy(fn() => $this->collector($runner)->collect());

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertStringContainsString('255', $e->getMessage());
        self::assertStringContainsString('Uncaught Exception: Cannot reach redis://cache', $e->getMessage());
        self::assertStringNotContainsString('s3cret', $e->getMessage());
        self::assertLessThanOrEqual(500, mb_strlen($e->getMessage()));
    }

    public function testAnUnknownExitCodeIsSaidSo(): void
    {
        $runner = (new FakeProcessRunner())->answer(null, '', null, 'Killed');

        $e = Failures::thrownBy(fn() => $this->collector($runner)->collect());

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertStringContainsString('exit code: unknown', $e->getMessage());
        self::assertStringNotContainsString('NULL', $e->getMessage());
    }

    public function testAConsoleThatDoesNotStartInTimeIsAFailure(): void
    {
        foreach (['', '{"transp'] as $output) {
            $runner = (new FakeProcessRunner())->answer(null, $output, null, timedOut: true);

            $e = Failures::thrownBy(fn() => $this->collector($runner)->collect());

            self::assertInstanceOf(\RuntimeException::class, $e);
            self::assertStringContainsString('did not answer in time', $e->getMessage());
        }
    }

    public function testAReasonIsCleanedAgain(): void
    {
        $output = "{\"transports\":[\"orders\"]}\n"
            . json_encode(['name' => 'orders', 'error' => 'Cannot reach amqp://guest:s3cret@broker ' . str_repeat('x', 600)]) . "\n";

        $reason = $this->collect($output)['transports'][0]['reason'];

        self::assertIsString($reason);
        self::assertStringStartsWith('Cannot reach amqp://broker ', $reason);
        self::assertSame(500, mb_strlen($reason));
    }

    public function testAnEmptyReasonIsSaidSo(): void
    {
        $output = "{\"transports\":[\"orders\"]}\n{\"name\":\"orders\",\"error\":\"\"}\n";

        self::assertSame([['name' => 'orders', 'count' => null, 'reason' => 'no reason given']], $this->collect($output)['transports']);
    }

    public function testNamesAreBounded(): void
    {
        $long = str_repeat('n', 250);
        $names = array_map(static fn(int $i): string => 't' . $i, range(1, 60));
        $output = json_encode(['transports' => [$long, ...$names]]) . "\n"
            . json_encode(['name' => $long, 'count' => 9]) . "\n";

        $transports = $this->collect($output)['transports'];

        self::assertCount(50, $transports);
        self::assertSame(['name' => str_repeat('n', 200), 'count' => 9, 'reason' => null], $transports[0]);
        self::assertSame(array_slice($names, 0, 49), array_column(array_slice($transports, 1), 'name'));
    }

    public function testWithoutConsoleItIsAMissingPrerequisite(): void
    {
        $runner = new FakeProcessRunner();
        unlink($this->projectDir . '/bin/console');

        $e = Failures::thrownBy(fn() => $this->collector($runner)->collect());

        self::assertInstanceOf(MissingPrerequisiteException::class, $e);
        self::assertStringContainsString('bin/console', $e->getMessage());
        self::assertSame([], $runner->calls);
    }

    public function testWithoutPhpOnTheCommandLineItIsAMissingPrerequisite(): void
    {
        $runner = new FakeProcessRunner();
        $collector = new QueuesCollector($runner, new PhpCli(static fn(): false => false), $this->projectDir, 'prod', false);

        self::assertInstanceOf(MissingPrerequisiteException::class, Failures::thrownBy($collector->collect(...)));
        self::assertSame([], $runner->calls);
    }

    /**
     * @return array{transports: array<string, array{count: ?int, reason: ?string}>}
     */
    private function collect(string $output): array
    {
        return $this->collector((new FakeProcessRunner())->answer(null, $output))->collect();
    }

    private function collector(FakeProcessRunner $runner, string $environment = 'prod', bool $debug = false): QueuesCollector
    {
        return new QueuesCollector($runner, new PhpCli(static fn(): string => self::PHP), $this->projectDir, $environment, $debug);
    }
}
