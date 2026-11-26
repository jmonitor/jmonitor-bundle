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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class UserRecordingTest extends WebTestCase
{
    use PollsThePage;

    private const ADMIN = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test'];
    private const USER = ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'test'];

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-user-recording-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testTheSignedInUserIsStillKnownWhenTheResponseIsSent(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);
        $client->disableReboot();
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $storage = static::getContainer()->get('security.untracked_token_storage');
        static::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        static::assertInstanceOf(TokenStorageInterface::class, $storage);
        $seen = [];
        $dispatcher->addListener(KernelEvents::RESPONSE, static function (ResponseEvent $event) use ($storage, &$seen): void {
            if ($event->isMainRequest()) {
                $seen[] = $storage->getToken()?->getUserIdentifier();
            }
        }, 4096);

        $client->request('GET', '/hello/world', server: self::ADMIN);
        static::assertResponseIsSuccessful();
        $client->request('GET', '/hello/world?_switch_user=user', server: self::ADMIN);
        static::assertResponseIsSuccessful();
        $client->request('GET', '/boom', server: self::USER);
        static::assertResponseStatusCodeSame(500);
        $client->request('GET', '/hello/world');
        static::assertResponseIsSuccessful();

        static::assertSame(['admin', 'user', 'user', null], $seen);
    }

    public function testTheRequestsOfEachSignedInUserAreListed(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        static::assertResponseIsSuccessful();
        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world', server: self::ADMIN);
            static::assertResponseIsSuccessful();
        }
        $client->request('GET', '/boom', server: self::USER);
        static::assertResponseStatusCodeSame(500);
        $crawler = self::requestUntil($client, '/jmonitor/users', 'tr[data-jm-user="user"]');

        static::assertResponseIsSuccessful();
        static::assertSame('Users', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text());
        $rows = self::userRows($crawler);
        static::assertSame(['admin', 'user'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-user')));
        static::assertSame(['3', '1'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-requests')));
        static::assertSame(['', '1 × 5xx'], $rows->each(static fn(Crawler $row): string => trim($row->filter('td')->eq(3)->text())));
        static::assertSame('1', $crawler->filter('.jm-card')->eq(3)->filter('.jm-card__value')->text());
    }

    public function testAUserWhoMetAServerErrorIsTaggedAndBringsTheCheckOfRequests(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        static::assertResponseIsSuccessful();
        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world', server: self::ADMIN);
            static::assertResponseIsSuccessful();
        }
        $client->request('GET', '/boom', server: self::USER);
        static::assertResponseStatusCodeSame(500);
        self::requestUntil($client, '/jmonitor/users', 'tr[data-jm-user="admin"][data-jm-requests="3"]');
        $crawler = self::requestUntil($client, '/jmonitor/users', 'tr[data-jm-user="user"][data-jm-requests="1"]');

        static::assertSame(
            ['jm-lead', 'jm-panel', 'jm-cards', 'jm-panel', 'jm-guide'],
            $crawler->filter('.jm-screen > section')->each(static fn(Crawler $block): string => explode(' ', (string) $block->attr('class'))[0]),
        );
        static::assertSame('2 signed-in users made 4 of the 5 requests in the last hour.', $crawler->filter('h1.jm-lead__title')->text());
        static::assertSame('They met 1 server error. The most active one made 75 % of their requests.', $crawler->filter('.jm-lead__detail')->text());

        static::assertSame(['requests.server_errors'], $crawler->filter('[data-jm-check]')->each(static fn(Crawler $line): string => (string) $line->attr('data-jm-check')));
        $check = $crawler->filter('[data-jm-check="requests.server_errors"]');
        static::assertSame('bad', $check->attr('data-jm-severity'));
        static::assertSame('1 server error · GET app_boom answered with a 5xx status once.', $check->filter('.jm-check__sentence')->text());
        static::assertSame(['Requests', '/jmonitor/requests?period=1h'], [$check->filter('.jm-check__where a')->text(), $check->filter('.jm-check__where a')->attr('href')]);

        static::assertSame(['Active users', 'Their requests', 'Slow requests', 'Server errors'], $crawler->filter('.jm-card__label')->each(static fn(Crawler $label): string => $label->text()));
        static::assertSame(['2', '4', '0', '1'], $crawler->filter('.jm-card__value')->each(static fn(Crawler $value): string => $value->text()));
        static::assertSame('80 % of all the requests of the last hour.', $crawler->filter('.jm-card__hint')->eq(1)->text());
        static::assertStringContainsString('jm-card--bad', (string) $crawler->filter('.jm-card')->eq(3)->attr('class'));

        $table = $crawler->filter('section[aria-labelledby="jm-users-title"]');
        static::assertSame('2 users, the most active first', $table->filter('.jm-panel__meta')->text());
        static::assertSame('The other request came from a visitor who was not signed in.', $table->filter('.jm-panel__footnote')->text());
        static::assertSame(['3 · 75 %', '1 · 25 %'], $table->filter('tbody .jm-bar__value')->each(static fn(Crawler $value): string => $value->text()));
        static::assertSame(['1 × 5xx'], $table->filter('tr[data-jm-user="user"] .jm-tag--bad')->each(static fn(Crawler $tag): string => $tag->text()));
        static::assertCount(0, $table->filter('tr[data-jm-user="admin"] .jm-tag'));

        static::assertSame(
            ['One user far above the others.', 'A user with errors or slow pages.', 'Who is counted.'],
            $crawler->filter('.jm-guide li strong')->each(static fn(Crawler $term): string => $term->text()),
        );
        static::assertSame('1 critical in the last hour', $crawler->filter('.jm-subnav__item')->eq(0)->filter('.jm-nav__badge')->attr('title'));
        static::assertCount(0, $crawler->filter('.jm-subnav__item[aria-current="page"] .jm-nav__badge'));

        foreach (['slow', 'errors'] as $removed) {
            $old = $client->request('GET', '/jmonitor/users', ['sort' => $removed, 'dir' => 'asc']);

            static::assertResponseIsSuccessful($removed);
            static::assertSame(['Requests'], $old->filter('th.is-sorted .jm-sort span:first-child')->each(static fn(Crawler $span): string => $span->text()), $removed);
        }
    }

    public function testAnImpersonatedRequestCountsForTheImpersonatedUser(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world?_switch_user=user', server: self::ADMIN);
        static::assertResponseIsSuccessful();
        $crawler = self::requestUntil($client, '/jmonitor/users', 'tr[data-jm-user]');

        static::assertSame(['user'], self::userRows($crawler)->each(static fn(Crawler $row): ?string => $row->attr('data-jm-user')));
    }

    public function testAnAuthenticationThatNobodyAskedForIsNotTriggered(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);
        $client->disableReboot();

        $client->request('GET', '/lazy/me', server: self::ADMIN);
        static::assertResponseIsSuccessful();
        $client->request('GET', '/lazy/hello/world');
        static::assertResponseIsSuccessful();
        $storage = static::getContainer()->get('security.untracked_token_storage');
        static::assertInstanceOf(TokenStorage::class, $storage);
        static::assertNotNull((new \ReflectionProperty(TokenStorage::class, 'initializer'))->getValue($storage), 'The authentication of the lazy firewall ran.');
        $client->request('GET', '/lazy/me');
        static::assertResponseIsSuccessful();
        $client->request('GET', '/hello/world', server: self::USER);
        static::assertResponseIsSuccessful();
        $crawler = self::requestUntil($client, '/jmonitor/users', 'tr[data-jm-user="user"]');

        $rows = self::userRows($crawler);
        static::assertSame(['admin', 'user'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-user')));
        static::assertSame(['2', '1'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-requests')));
    }

    public function testAHostileIdentifierIsShownEscaped(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false] + $this->recording()['recording']]]);
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        static::assertInstanceOf(Connection::class, $connection);
        $now = time();
        (new DbalStorage($connection, 604800))->store(
            [new Aggregate(Period::OneHour, Period::OneHour->bucketFor($now), Metric::USER_DURATION, '<script>alert(1)</script>', 1, 20.0, 20.0)],
            $now,
        );

        $crawler = $client->request('GET', '/jmonitor/users');
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertSame('<script>alert(1)</script>', $crawler->filter('tr[data-jm-user] th')->text());
        static::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        static::assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function testWithoutTheSecurityBundleThereIsNoUsersScreen(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'security' => false]);

        $client->request('GET', '/hello/world');
        static::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/jmonitor/');
        static::assertResponseIsSuccessful();
        static::assertNotContains('Users', $crawler->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()));

        $client->request('GET', '/jmonitor/users');
        static::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, security?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, $options['jmonitor'] ?? [], withSecurity: $options['security'] ?? true);
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

    private static function userRows(Crawler $crawler): Crawler
    {
        return $crawler->filter('section[aria-labelledby="jm-users-title"] tbody tr');
    }
}
