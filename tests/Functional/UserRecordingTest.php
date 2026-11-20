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
        static::assertSame('Users', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        $rows = self::userRows($crawler);
        static::assertSame(['admin', 'user'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-user')));
        static::assertSame(['3', '1'], $rows->each(static fn(Crawler $row): ?string => $row->attr('data-jm-requests')));
        static::assertSame(['0', '1'], $rows->each(static fn(Crawler $row): string => trim($row->filter('td')->eq(4)->text())));
        static::assertSame('1', $crawler->filter('.jm-card')->eq(3)->filter('.jm-card__value')->text());
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
        static::assertNotContains('Users', $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));

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
