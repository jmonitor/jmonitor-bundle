<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class HttpClientRecordingTest extends WebTestCase
{
    use PollsThePage;

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-http-client-recording-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testOutgoingCallsAreListedPerHostAndOnTheirRoute(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 2; ++$i) {
            $client->request('GET', '/call');
            static::assertResponseIsSuccessful();
        }
        $crawler = self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-http tr[data-jm-host="api.example.com:8443"][data-jm-calls="2"]');

        static::assertResponseIsSuccessful();
        static::assertSame('Cache & HTTP', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text());
        $rows = self::hostRows($crawler);
        static::assertSame(['api.example.com:8443'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-host')));
        static::assertSame(['2'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-calls')));
        static::assertStringNotContainsString('secret', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/jmonitor/');
        $headers = $crawler->filter('section[aria-labelledby="jm-routes-title"] thead th')->each(
            static fn(Crawler $th): string => trim(($th->filter('span')->count() > 0 ? $th->filter('span')->first() : $th)->text()),
        );
        $column = array_search('Where the average goes', $headers, true);
        static::assertIsInt($column, 'The Requests screen does not split the average of a route.');
        $cell = $crawler->filter('tr[data-jm-route="GET app_call"]')->filter('th, td')->eq($column);
        static::assertMatchesRegularExpression('/^HTTP out \S+ ms/', trim($cell->filter('.jm-split__text')->text()));
        static::assertMatchesRegularExpression('/, HTTP out \S+ ms$/', trim($cell->filter('.jm-visually-hidden')->text()));
    }

    public function testACallRetriedByTheApplicationCountsOnce(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/call-flaky');
        static::assertResponseIsSuccessful();
        $crawler = self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-http tr[data-jm-host="flaky.example.com"][data-jm-calls="1"]');

        $row = self::hostRows($crawler);
        static::assertSame(['flaky.example.com'], $row->each(static fn(Crawler $row): ?string => $row->attr('data-jm-host')));
        static::assertSame(['1'], $row->each(static fn(Crawler $row): ?string => $row->attr('data-jm-calls')));
        static::assertSame('0', trim($row->filter('td')->eq(5)->text()));
    }

    public function testAHostileHostIsShownEscaped(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false] + $this->recording()['recording']]]);
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        static::assertInstanceOf(Connection::class, $connection);
        $now = time();
        (new DbalStorage($connection, 604800))->store(
            [new Aggregate(Period::OneHour, Period::OneHour->bucketFor($now), Metric::HTTP_DURATION, '<script>alert(1)</script>', 1, 20.0, 20.0)],
            $now,
        );

        $crawler = $client->request('GET', '/jmonitor/cache-http');
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertSame('<script>alert(1)</script>', $crawler->filter('section#jm-http tr[data-jm-host] th')->text());
        static::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        static::assertStringNotContainsString('<script>alert(1)', $html);
    }

    /**
     * @param array{jmonitor?: array<string, mixed>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, $options['jmonitor'] ?? []);
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @return array{recording: array<string, mixed>}
     */
    private function recording(): array
    {
        return ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]];
    }

    private static function hostRows(Crawler $crawler): Crawler
    {
        return $crawler->filter('section#jm-http tbody tr');
    }
}
