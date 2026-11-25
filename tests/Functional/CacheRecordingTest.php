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

final class CacheRecordingTest extends WebTestCase
{
    use PollsThePage;

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-cache-recording-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testTheReadsAndWritesOfTheApplicationPoolAreListed(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/cached');
            static::assertResponseIsSuccessful();
            static::assertSame('Computed once', $client->getResponse()->getContent());
        }
        $crawler = self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-cache tr[data-jm-pool="cache.app"][data-jm-reads="3"]');

        static::assertResponseIsSuccessful();
        static::assertSame('Cache & HTTP', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text());
        $rows = $crawler->filter('section#jm-cache tbody tr');
        static::assertSame(['cache.app'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-pool')));
        static::assertSame(['3'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-reads')));
        static::assertSame(
            ['3', '67 %', '1', '1', '0', ''],
            $rows->filter('td')->each(static fn(Crawler $cell): string => trim($cell->text())),
        );
    }

    public function testALowHitRateIsWorthALookAndBadgesTheTab(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/cached');
            static::assertResponseIsSuccessful();
        }
        $client->request('GET', '/cache-misses', ['count' => '20']);
        static::assertResponseIsSuccessful();
        self::requestUntil($client, '/jmonitor/cache-http', '[data-jm-check="cache.low_hit_rate"]');
        $crawler = self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-cache tr[data-jm-pool="cache.app"][data-jm-reads="23"]');

        static::assertSame('23 cache reads (8.7 % found) in the last hour.', $crawler->filter('h1.jm-lead__title')->text());
        static::assertSame('1 pool has a low hit rate.', $crawler->filter('.jm-lead__detail')->text());
        $check = $crawler->filter('[data-jm-check="cache.low_hit_rate"]');
        static::assertCount(1, $check);
        static::assertSame(['cache.low_hit_rate'], $crawler->filter('[data-jm-check]')->each(static fn(Crawler $line): string => (string) $line->attr('data-jm-check')));
        static::assertSame('Worth a look', $crawler->filter('.jm-screen > section.jm-checks')->attr('aria-label'));
        static::assertSame('warn', $check->attr('data-jm-severity'));
        static::assertSame('1 pool with a low hit rate · cache.app found 8.7 % of its 23 reads.', $check->filter('.jm-check__sentence')->text());
        static::assertSame('Warning', $check->filter('.jm-check__mark')->text());
        static::assertCount(0, $check->filter('.jm-check__where'));

        $row = $crawler->filter('section#jm-cache tr[data-jm-pool="cache.app"]');
        static::assertSame(['low hit rate'], $row->filter('.jm-tag--warn')->each(static fn(Crawler $tag): string => $tag->text()));
        static::assertSame('8.7 %', $row->filter('.jm-bar__value')->text());
        $rate = $crawler->filter('section#jm-cache .jm-card')->eq(1);
        static::assertSame('Hit rate', $rate->filter('.jm-card__label')->text());
        static::assertStringContainsString('jm-card--warn', (string) $rate->attr('class'));

        $badge = $crawler->filter('.jm-subnav__item[aria-current="page"] .jm-nav__badge');
        static::assertCount(1, $badge);
        static::assertSame('1 warning in the last hour', $badge->attr('title'));
        static::assertStringContainsString('jm-nav__badge--warn', (string) $badge->attr('class'));
    }

    public function testAHostilePoolNameIsShownEscaped(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false] + $this->recording()['recording']]]);
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        static::assertInstanceOf(Connection::class, $connection);
        $now = time();
        (new DbalStorage($connection, 604800))->store(
            [new Aggregate(Period::OneHour, Period::OneHour->bucketFor($now), Metric::CACHE_HIT, '<script>alert(1)</script>', 1, 0.0, 0.0)],
            $now,
        );

        $crawler = $client->request('GET', '/jmonitor/cache-http');
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertSame('<script>alert(1)</script>', $crawler->filter('section#jm-cache tr[data-jm-pool] th')->text());
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
}
