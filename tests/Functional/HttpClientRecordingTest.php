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

        $crawler = $client->request('GET', '/jmonitor/requests');
        $headers = $crawler->filter('#jm-routes-wrap thead th')->each(
            static fn(Crawler $th): string => trim(($th->filter('span')->count() > 0 ? $th->filter('span')->first() : $th)->text()),
        );
        $column = array_search('Where the average goes', $headers, true);
        static::assertIsInt($column, 'The Requests screen does not split the average of a route.');
        $cell = $crawler->filter('tr[data-jm-route="GET app_call"]')->filter('th, td')->eq($column);
        static::assertMatchesRegularExpression('/^HTTP \S+ ms$/', trim($cell->filter('.jm-split__text')->text()));
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
        static::assertSame('', trim($row->filter('td.jm-col-problems')->text()));
    }

    public function testACallWithoutAnswerIsCriticalOnCacheAndHttp(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/call');
        static::assertResponseIsSuccessful();
        for ($i = 0; $i < 2; ++$i) {
            $client->request('GET', '/call-down');
            static::assertResponseIsSuccessful();
            static::assertSame('No answer', $client->getResponse()->getContent());
        }
        self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-http tr[data-jm-host="api.example.com:8443"][data-jm-calls="1"]');
        $crawler = self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-http tr[data-jm-host="down.example.com"][data-jm-calls="2"]');

        static::assertSame('3 outgoing calls in the last hour.', $crawler->filter('h1.jm-lead__title')->text());
        static::assertSame('2 calls got no answer.', $crawler->filter('.jm-lead__detail')->text());
        $check = $crawler->filter('[data-jm-check="http.failures"]');
        static::assertCount(1, $check);
        static::assertSame(['http.failures'], $crawler->filter('[data-jm-check]')->each(static fn(Crawler $line): string => (string) $line->attr('data-jm-check')));
        static::assertSame('bad', $check->attr('data-jm-severity'));
        static::assertSame('2 calls without an answer · down.example.com did not answer 2 times.', $check->filter('.jm-check__sentence')->text());
        static::assertSame('Critical', $check->filter('.jm-check__mark')->text());
        static::assertCount(0, $check->filter('.jm-check__where'));

        $hosts = self::hostRows($crawler)->each(static fn(Crawler $row): ?string => $row->attr('data-jm-host'));
        sort($hosts);
        static::assertSame(['api.example.com:8443', 'down.example.com'], $hosts);
        static::assertSame(['2 no answer'], $crawler->filter('section#jm-http tr[data-jm-host="down.example.com"] .jm-tag--bad')->each(static fn(Crawler $tag): string => $tag->text()));
        static::assertSame('', trim($crawler->filter('section#jm-http tr[data-jm-host="api.example.com:8443"] td.jm-col-problems')->text()));
        $noAnswer = $crawler->filter('section#jm-http .jm-card')->eq(3);
        static::assertSame(['No answer', '2'], [$noAnswer->filter('.jm-card__label')->text(), $noAnswer->filter('.jm-card__value')->text()]);
        static::assertStringContainsString('jm-card--bad', (string) $noAnswer->attr('class'));

        $badge = $crawler->filter('.jm-subnav__item[aria-current="page"] .jm-nav__badge');
        static::assertCount(1, $badge);
        static::assertSame('1 critical in the last hour', $badge->attr('title'));
        static::assertStringContainsString('jm-nav__badge--bad', (string) $badge->attr('class'));
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
