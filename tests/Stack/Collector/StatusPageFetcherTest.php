<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\StatusPageFetcher;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusPageFetcherTest extends TestCase
{
    public function testItSendsTheProbeHeaderAndFollowsNoRedirect(): void
    {
        $asked = [];
        $open = static function (string $url, array $http) use (&$asked): array {
            $asked[] = [$url, $http];

            return ['Total Accesses: 12', ['HTTP/1.1 200 OK', 'Content-Type: text/plain']];
        };

        $body = (new StatusPageFetcher(open: $open, urlFopen: true))->fetch('http://localhost/server-status?auto');

        self::assertSame('Total Accesses: 12', $body);
        self::assertSame([['http://localhost/server-status?auto', [
            'timeout' => 2.0,
            'follow_location' => 0,
            'max_redirects' => 0,
            'ignore_errors' => true,
            'header' => "X-Jmonitor-Probe: 1\r\nConnection: close",
        ]]], $asked);
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function answersOfTheApplication(): iterable
    {
        yield 'not found' => [['HTTP/1.1 404 Not Found', 'Content-Type: text/html'], 404];
        yield 'redirected to a login page' => [['HTTP/1.1 302 Found', 'Location: /login'], 302];
        yield 'server error' => [['HTTP/1.1 500 Internal Server Error'], 500];
        yield 'unauthorized' => [['HTTP/1.1 401 Unauthorized', 'WWW-Authenticate: Basic'], 401];
    }

    /**
     * @param list<string> $headers
     */
    #[DataProvider('answersOfTheApplication')]
    public function testAnAnswerThatIsNot200IsNotAStatusPage(array $headers, int $code): void
    {
        $fetcher = new StatusPageFetcher(open: static fn(): array => ['<html>An application page</html>', $headers], urlFopen: true);

        $this->expectException(MissingPrerequisiteException::class);
        $this->expectExceptionMessage(sprintf('http://localhost/server-status answered HTTP %d: it is not a status page.', $code));

        $fetcher->fetch('http://localhost/server-status');
    }

    public function testTheStatusOfTheLastResponseCounts(): void
    {
        $headers = ['HTTP/1.1 100 Continue', 'HTTP/1.1 200 OK', 'Content-Type: text/plain'];

        $body = (new StatusPageFetcher(open: static fn(): array => ['Active connections: 1', $headers], urlFopen: true))->fetch('http://localhost/nginx_status');

        self::assertSame('Active connections: 1', $body);
    }

    public function testNothingAnsweringIsAnUnreachableServer(): void
    {
        $open = static function (): array {
            @trigger_error('file_get_contents(http://localhost:2019/metrics): Failed to open stream: Connection refused', \E_USER_WARNING);

            return [null, []];
        };

        $this->expectException(UnreachableServerException::class);
        $this->expectExceptionMessage('Nothing answered at http://localhost:2019/metrics: Failed to open stream: Connection refused');

        (new StatusPageFetcher(open: $open, urlFopen: true))->fetch('http://localhost:2019/metrics');
    }

    public function testWithoutUrlFopenItIsAMissingPrerequisite(): void
    {
        $opened = false;
        $open = static function () use (&$opened): array {
            $opened = true;

            return ['', ['HTTP/1.1 200 OK']];
        };

        try {
            (new StatusPageFetcher(open: $open, urlFopen: false))->fetch('http://localhost/server-status');
            self::fail('The page was read.');
        } catch (MissingPrerequisiteException $e) {
            self::assertSame('PHP cannot read http://localhost/server-status: allow_url_fopen is off.', $e->getMessage());
        }

        self::assertFalse($opened);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatAreNotHttp(): iterable
    {
        yield 'a local file' => ['file:///etc/passwd'];
        yield 'a path' => ['/var/run/x'];
    }

    #[DataProvider('addressesThatAreNotHttp')]
    public function testOnlyHttpAddressesAreRead(string $url): void
    {
        $opened = false;
        $open = static function () use (&$opened): array {
            $opened = true;

            return ['', ['HTTP/1.1 200 OK']];
        };

        try {
            (new StatusPageFetcher(open: $open, urlFopen: true))->fetch($url);
            self::fail('The address was read.');
        } catch (MissingPrerequisiteException $e) {
            self::assertSame(sprintf('%s is not an http(s) address.', $url), $e->getMessage());
        }

        self::assertFalse($opened);
    }
}
