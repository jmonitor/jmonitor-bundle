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
        $crawler = self::requestUntil($client, '/jmonitor/exceptions', 'tr[data-jm-exception="RuntimeException"][data-jm-count="3"]');

        static::assertResponseIsSuccessful();
        static::assertSame('Exceptions', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        static::assertCount(1, self::exceptionRows($crawler));
        static::assertSame('3', self::exceptionRows($crawler)->attr('data-jm-count'));
        static::assertStringNotContainsString('NotFoundHttpException', (string) $client->getResponse()->getContent());

        $where = self::whereRows($crawler);
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
        $where = self::whereRows($crawler);
        static::assertCount(1, $where);
        static::assertSame('1', $where->attr('data-jm-count'));
        static::assertSame('app_sub_boom', trim($where->filter('.jm-route')->text()));
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
        return $crawler->filter('section[aria-labelledby="jm-exceptions-title"] tbody tr');
    }

    private static function whereRows(Crawler $crawler): Crawler
    {
        return $crawler->filter('section[aria-labelledby="jm-where-title"] tbody tr');
    }
}
