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

final class ExceptionRecordingTest extends WebTestCase
{
    use PollsThePage;

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-exception-recording-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testTheExceptionsBehindServerErrorsAreListedWithWhereTheyWereThrown(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/handled');
        static::assertResponseStatusCodeSame(404);
        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/boom');
            static::assertResponseStatusCodeSame(500);
        }
        $crawler = self::requestUntil($client, '/jmonitor/exceptions', 'tr[data-jm-exception="RuntimeException"]:not(.jm-row--nested)[data-jm-count="3"]');

        static::assertResponseIsSuccessful();
        static::assertSame('Exceptions', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text());
        static::assertCount(1, self::exceptionRows($crawler));
        static::assertSame('3', self::exceptionRows($crawler)->attr('data-jm-count'));
        static::assertStringNotContainsString('NotFoundHttpException', (string) $client->getResponse()->getContent());

        $where = self::placeRows($crawler);
        static::assertCount(1, $where);
        static::assertSame('RuntimeException', $where->attr('data-jm-exception'));
        static::assertSame('3', $where->attr('data-jm-count'));
        static::assertSame('GET', trim($where->filter('.jm-method')->text()));
        static::assertSame('app_boom', trim($where->filter('.jm-route')->text()));
    }

    public function testAnExceptionThrownInASubRequestCountsOnce(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/sub-boom');
        static::assertResponseStatusCodeSame(500);
        $crawler = self::requestUntil($client, '/jmonitor/exceptions', 'tr[data-jm-exception="RuntimeException"]');

        static::assertSame('1', self::exceptionRows($crawler)->attr('data-jm-count'));
        $where = self::placeRows($crawler);
        static::assertCount(1, $where);
        static::assertSame('1', $where->attr('data-jm-count'));
        static::assertSame('app_sub_boom', trim($where->filter('.jm-route')->text()));
    }

    public function testAnExceptionShowsUnderItsClassWithItsPlaceAndTheCheckOfThatPlace(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 2; ++$i) {
            $client->request('GET', '/boom');
            static::assertResponseStatusCodeSame(500);
        }
        $crawler = self::requestUntil($client, '/jmonitor/exceptions', 'tr.jm-row--nested[data-jm-exception="RuntimeException"][data-jm-place="request GET app_boom"][data-jm-count="2"]');

        static::assertSame(
            ['jm-lead', 'jm-panel', 'jm-cards', 'jm-panel', 'jm-guide'],
            $crawler->filter('.jm-screen > section')->each(static fn(Crawler $block): string => explode(' ', (string) $block->attr('class'))[0]),
        );
        static::assertCount(1, $crawler->filter('h1'));
        static::assertSame('2 exceptions in the last hour, all of one class.', $crawler->filter('h1.jm-lead__title')->text());
        static::assertSame('All thrown in requests.', $crawler->filter('.jm-lead__detail')->text());

        static::assertSame('Worth a look', $crawler->filter('section.jm-checks')->attr('aria-label'));
        static::assertSame(['requests.server_errors'], $crawler->filter('[data-jm-check]')->each(static fn(Crawler $line): string => (string) $line->attr('data-jm-check')));
        $check = $crawler->filter('[data-jm-check="requests.server_errors"]');
        static::assertSame('2 server errors · GET app_boom answered with a 5xx status 2 times.', $check->filter('.jm-check__sentence')->text());
        static::assertSame(['Requests', '/jmonitor/requests?period=1h'], [$check->filter('.jm-check__where a')->text(), $check->filter('.jm-check__where a')->attr('href')]);

        static::assertSame(['Exceptions', 'Classes', 'Places', 'Last one'], $crawler->filter('.jm-card__label')->each(static fn(Crawler $label): string => $label->text()));
        static::assertSame(['2', '1', '1'], \array_slice($crawler->filter('.jm-card__value')->each(static fn(Crawler $value): string => $value->text()), 0, 3));

        $table = $crawler->filter('section[aria-labelledby="jm-exceptions-title"]');
        static::assertSame('By class', $table->filter('.jm-panel__title')->text());
        static::assertSame('1 class, each with where it was thrown', $table->filter('.jm-panel__meta')->text());
        static::assertSame('JMonitor counts exceptions. It keeps no stack trace: the trace is in your logs or your error tracker.', $table->filter('.jm-panel__footnote')->text());
        static::assertSame([null, 'jm-row--nested'], $table->filter('tbody tr')->each(static fn(Crawler $row): ?string => $row->attr('class')));
        static::assertSame(['RuntimeException', 'RuntimeException'], $table->filter('tbody tr')->each(static fn(Crawler $row): ?string => $row->attr('data-jm-exception')));
        static::assertSame(['2', '2'], $table->filter('tbody tr')->each(static fn(Crawler $row): ?string => $row->attr('data-jm-count')));
        static::assertSame(['GET', 'app_boom'], [trim(self::placeRows($crawler)->filter('.jm-method')->text()), self::placeRows($crawler)->filter('.jm-route')->text()]);

        static::assertSame(
            ['Class, then place.', 'New or usual?', 'An empty screen is the goal.'],
            $crawler->filter('.jm-guide li strong')->each(static fn(Crawler $term): string => $term->text()),
        );

        $tabs = $crawler->filter('.jm-subnav__item');
        static::assertSame('1 critical in the last hour', $tabs->eq(0)->filter('.jm-nav__badge')->attr('title'));
        static::assertCount(0, $crawler->filter('.jm-subnav__item[aria-current="page"] .jm-nav__badge'));
    }

    public function testAHostileClassNameIsShownEscaped(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false] + $this->recording()['recording']]]);
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        static::assertInstanceOf(Connection::class, $connection);
        $now = time();
        (new DbalStorage($connection, 604800))->store(
            [new Aggregate(Period::OneHour, Period::OneHour->bucketFor($now), Metric::EXCEPTION_COUNT, '<script>alert(1)</script>', 1, 0.0, (float) $now)],
            $now,
        );

        $crawler = $client->request('GET', '/jmonitor/exceptions');
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertSame('<script>alert(1)</script>', $crawler->filter('tr[data-jm-exception] code')->text());
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
     * @return array<string, mixed>
     */
    private function recording(): array
    {
        return ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]];
    }

    private static function exceptionRows(Crawler $crawler): Crawler
    {
        return $crawler->filter('section[aria-labelledby="jm-exceptions-title"] tbody tr:not(.jm-row--nested)');
    }

    private static function placeRows(Crawler $crawler): Crawler
    {
        return $crawler->filter('section[aria-labelledby="jm-exceptions-title"] tbody tr.jm-row--nested');
    }
}
