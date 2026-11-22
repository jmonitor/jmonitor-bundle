<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\ApacheStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use PHPUnit\Framework\TestCase;

final class ApacheStatusCollectorTest extends TestCase
{
    private const ENDPOINT = 'http://localhost/server-status';
    private const MESSAGE = 'Cannot read the Apache status at http://localhost/server-status. Enable mod_status with a "SetHandler server-status" location reachable from PHP, and check "jmonitor.stack.apache.endpoint".';

    public function testItReadsTheMachineReadableStatus(): void
    {
        $fetcher = new FakeUrlFetcher(self::report());
        $collector = new ApacheStatusCollector(self::ENDPOINT, $fetcher);

        $payload = $collector->collect();

        self::assertSame('http://localhost/server-status?auto', $fetcher->url);
        self::assertSame(3, $payload['workers']['busy']);
        self::assertSame(47, $payload['workers']['idle']);
        self::assertSame(52000, $payload['total_accesses']);
        self::assertSame(2080.0, $payload['total_duration'], 'in seconds');
        self::assertSame('event', $payload['server_mpm']);
        self::assertSame('apache', $collector->getName());
        self::assertSame(2, $collector->getVersion());
    }

    public function testTheModulesAreNotKept(): void
    {
        $payload = (new ApacheStatusCollector(self::ENDPOINT, new FakeUrlFetcher(self::report())))->collect();

        self::assertArrayNotHasKey('modules', $payload);
        self::assertArrayHasKey('scoreboard', $payload);
    }

    public function testItReadsTheReportOfApache2471(): void
    {
        $report = "localhost\nServerVersion: Apache/2.4.71 (Debian) PHP/8.4.28\nServerMPM: prefork\nTotal Accesses: 5\nTotal kBytes: 4\nTotal Duration: 870\nBusyWorkers: 2\nIdleWorkers: 4\nScoreboard: WW____.....\n";

        $payload = (new ApacheStatusCollector(self::ENDPOINT, new FakeUrlFetcher($report)))->collect();

        self::assertSame('Apache/2.4.71 (Debian) PHP/8.4.28', $payload['server_version']);
        self::assertSame('prefork', $payload['server_mpm']);
        self::assertSame(0.87, $payload['total_duration']);
        self::assertSame(4096, $payload['total_bytes']);
        self::assertSame(['busy' => 2, 'idle' => 4], $payload['workers']);
        self::assertSame(['W' => 2, '_' => 4, '.' => 5], $payload['scoreboard']);
    }

    public function testAReportWithoutDurationsHasNone(): void
    {
        $report = (string) preg_replace('/^(Total Duration|DurationPerReq): .*\n/m', '', self::report());

        $payload = (new ApacheStatusCollector(self::ENDPOINT, new FakeUrlFetcher($report)))->collect();

        self::assertNull($payload['total_duration']);
        self::assertNull($payload['duration_per_request']);
    }

    public function testAnApplicationPageInPlaceOfTheStatusIsAMissingPrerequisite(): void
    {
        $e = Failures::thrownBy(static fn() => (new ApacheStatusCollector(self::ENDPOINT, new FakeUrlFetcher("<html>\n<title>Welcome</title>\n</html>")))->collect());

        self::assertContains(MissingPrerequisiteException::class, Failures::causes($e));
        self::assertSame(self::MESSAGE, $e->getMessage());
        self::assertStringContainsString('mod_status', $e->getMessage());
        self::assertStringContainsString('jmonitor.stack.apache.endpoint', $e->getMessage());
    }

    public function testAnUnreachablePageStaysUnreachable(): void
    {
        $reader = new UnreachableServerException('Nothing answered at http://localhost/server-status?auto: Connection refused');

        $e = Failures::thrownBy(static fn() => (new ApacheStatusCollector(self::ENDPOINT, new FakeUrlFetcher(null, $reader)))->collect());

        self::assertContains(UnreachableServerException::class, Failures::causes($e));
        self::assertNotContains(MissingPrerequisiteException::class, Failures::causes($e));
        self::assertSame(self::MESSAGE, $e->getMessage());
        self::assertSame($reader, $e->getPrevious());
    }

    public function testAMissingPrerequisiteOfTheReaderStaysOne(): void
    {
        $reader = new MissingPrerequisiteException('http://localhost/server-status?auto answered HTTP 404: it is not a status page.');

        $e = Failures::thrownBy(static fn() => (new ApacheStatusCollector(self::ENDPOINT, new FakeUrlFetcher(null, $reader)))->collect());

        self::assertContains(MissingPrerequisiteException::class, Failures::causes($e));
        self::assertSame(self::MESSAGE, $e->getMessage());
        self::assertSame($reader, $e->getPrevious());
    }

    private static function report(): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/Apache/server-status.txt');
    }
}
