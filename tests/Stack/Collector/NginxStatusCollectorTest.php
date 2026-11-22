<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\NginxStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\CommandTrap;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use PHPUnit\Framework\TestCase;

final class NginxStatusCollectorTest extends TestCase
{
    private const ENDPOINT = 'http://localhost/nginx_status';
    private const MESSAGE = 'Cannot read the Nginx status at http://localhost/nginx_status. Enable stub_status in a location reachable from PHP, and check "jmonitor.stack.nginx.endpoint".';

    public function testItReadsStubStatus(): void
    {
        $fetcher = new FakeUrlFetcher((string) file_get_contents(\dirname(__DIR__) . '/Fixtures/Nginx/stub_status.txt'));
        $collector = new NginxStatusCollector(self::ENDPOINT, $fetcher);

        self::assertSame(
            ['status' => ['active' => 12, 'accepts' => 18450, 'handled' => 18450, 'requests' => 41200, 'reading' => 1, 'writing' => 3, 'waiting' => 8]],
            $collector->collect(),
        );
        self::assertSame(self::ENDPOINT, $fetcher->url);
        self::assertSame(1, $fetcher->calls);
        self::assertSame('nginx', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public function testItReadsTheStatusOfNginx130WithTrailingSpaces(): void
    {
        $page = "Active connections: 2 \nserver accepts handled requests\n 5 5 5 \nReading: 0 Writing: 2 Waiting: 0 \n";

        $payload = (new NginxStatusCollector(self::ENDPOINT, new FakeUrlFetcher($page)))->collect();

        self::assertSame(['active' => 2, 'accepts' => 5, 'handled' => 5, 'requests' => 5, 'reading' => 0, 'writing' => 2, 'waiting' => 0], $payload['status']);
    }

    public function testItRunsNoCommand(): void
    {
        $trap = new CommandTrap('nginx', 'getconf');

        try {
            (new NginxStatusCollector(self::ENDPOINT, new FakeUrlFetcher("Active connections: 2 \nserver accepts handled requests\n 5 5 5 \nReading: 0 Writing: 2 Waiting: 0 \n")))->collect();
            $started = $trap->started();
        } finally {
            $trap->release();
        }

        self::assertFalse($started);
    }

    public function testAPageThatIsNotStubStatusIsAMissingPrerequisite(): void
    {
        $e = Failures::thrownBy(static fn() => (new NginxStatusCollector(self::ENDPOINT, new FakeUrlFetcher("<html>\n<title>Welcome</title>\n</html>")))->collect());

        self::assertContains(MissingPrerequisiteException::class, Failures::causes($e));
        self::assertSame(self::MESSAGE, $e->getMessage());
    }

    public function testAnUnreachablePageStaysUnreachable(): void
    {
        $reader = new UnreachableServerException('Nothing answered at http://localhost/nginx_status: Connection refused');

        $e = Failures::thrownBy(static fn() => (new NginxStatusCollector(self::ENDPOINT, new FakeUrlFetcher(null, $reader)))->collect());

        self::assertContains(UnreachableServerException::class, Failures::causes($e));
        self::assertNotContains(MissingPrerequisiteException::class, Failures::causes($e));
        self::assertSame(self::MESSAGE, $e->getMessage());
        self::assertSame($reader, $e->getPrevious());
    }

    public function testAMissingPrerequisiteOfTheReaderStaysOne(): void
    {
        $reader = new MissingPrerequisiteException('PHP cannot read http://localhost/nginx_status: allow_url_fopen is off.');

        $e = Failures::thrownBy(static fn() => (new NginxStatusCollector(self::ENDPOINT, new FakeUrlFetcher(null, $reader)))->collect());

        self::assertContains(MissingPrerequisiteException::class, Failures::causes($e));
        self::assertSame($reader, $e->getPrevious());
    }
}
