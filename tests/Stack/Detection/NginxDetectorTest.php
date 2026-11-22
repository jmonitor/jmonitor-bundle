<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\NginxConfigCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\NginxStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\NginxDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeStatusPages;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class NginxDetectorTest extends TestCase
{
    private const ENDPOINT = 'http://localhost/nginx_status';

    public function testPhpFpmBehindNginxIsDetectedFromTheServerSoftwareWithoutReadingAnything(): void
    {
        $pages = new FakeStatusPages();
        $runner = new FakeProcessRunner();
        $endOfLife = new FakeUrlFetcher(null);
        $detector = new NginxDetector(self::ENDPOINT, self::requests('nginx/1.30.7'), $runner, true, $pages, $endOfLife);

        $detection = $detector->detect();

        self::assertSame(Component::Nginx, $detector->component());
        self::assertTrue($detection->isFound());
        self::assertSame(Component::Nginx, $detection->instances[0]->component);
        self::assertNull($detection->instances[0]->id);
        $collectors = $detection->instances[0]->collectors;
        self::assertNotNull($collectors);
        self::assertSame(['nginx', 'nginx.config', 'eol.nginx'], $collectors->names());

        $status = $collectors->get('nginx');
        self::assertNotNull($status);
        self::assertInstanceOf(NginxStatusCollector::class, $status->collector);
        self::assertSame(Lifetime::Dynamic, $status->lifetime);
        self::assertSame(CostClass::Network, $status->cost);
        self::assertTrue($status->trackDelta);
        self::assertFalse($status->shared);

        $config = $collectors->get('nginx.config');
        self::assertNotNull($config);
        self::assertInstanceOf(NginxConfigCollector::class, $config->collector);
        self::assertSame(Lifetime::Static, $config->lifetime);
        self::assertSame(CostClass::Slow, $config->cost);
        self::assertFalse($config->trackDelta);
        self::assertFalse($config->shared);

        $support = $collectors->get('eol.nginx');
        self::assertNotNull($support);
        self::assertInstanceOf(EndOfLifeCollector::class, $support->collector);
        self::assertSame(Lifetime::Day, $support->lifetime);
        self::assertSame(CostClass::Slow, $support->cost);
        self::assertFalse($support->trackDelta);
        self::assertTrue($support->shared);

        self::assertSame(0, $pages->calls);
        self::assertSame([], $runner->calls);
        self::assertSame(0, $endOfLife->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nginxServerSoftware(): iterable
    {
        yield 'with its version' => ['nginx/1.30.7'];
        yield 'without version' => ['nginx'];
        yield 'followed by a space' => ['nginx (Ubuntu)'];
        yield 'in capitals' => ['NGINX/1.30.7'];
    }

    #[DataProvider('nginxServerSoftware')]
    public function testTheServerSoftwareOfNginxIsNginx(string $software): void
    {
        self::assertTrue(self::detector(self::requests($software))->detect()->isFound());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherServerSoftware(): iterable
    {
        yield 'Apache' => ['Apache/2.4.71 (Debian)'];
        yield 'FrankenPHP' => ['FrankenPHP'];
        yield 'named after Nginx' => ['nginxish/1.0'];
        yield 'empty' => [''];
    }

    #[DataProvider('otherServerSoftware')]
    public function testAnotherServerIsUnavailable(string $software): void
    {
        $detection = self::detector(self::requests($software))->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('Nginx is not serving this page.', $detection->error);
    }

    public function testWithoutMainRequestNothingIsDetected(): void
    {
        $detection = self::detector(new RequestStack())->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('Nginx is not serving this page.', $detection->error);
    }

    public function testWithoutEndOfLifeThereIsNoSupportLookup(): void
    {
        $collectors = self::detector(self::requests('nginx/1.30.7'))->detect()->instances[0]->collectors;

        self::assertNotNull($collectors);
        self::assertSame(['nginx', 'nginx.config'], $collectors->names());
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function fallbackVersions(): iterable
    {
        yield 'with its version' => ['nginx/1.26.2', '1.26.2'];
        yield 'without version' => ['nginx', null];
    }

    #[DataProvider('fallbackVersions')]
    public function testTheVersionOfTheServerSoftwareIsTheFallback(string $software, ?string $version): void
    {
        $runner = (new FakeProcessRunner())
            ->answer(['sh', '-c', 'nginx -V 2>&1'], '', 1)
            ->answer(['sh', '-c', 'nginx -T'], '', 1)
            ->answer(['sh', '-c', 'getconf _NPROCESSORS_ONLN'], "4\n");
        $collectors = self::detector(self::requests($software), $runner)->detect()->instances[0]->collectors;
        self::assertNotNull($collectors);

        $payload = $collectors->get('nginx.config')?->collector->collect();

        self::assertIsArray($payload);
        self::assertSame($version, $payload['version']);
    }

    public function testTheCollectorsReadTheConfiguredEndpointAndTheSupportOfNginx(): void
    {
        $pages = new FakeStatusPages();
        $endOfLife = new FakeUrlFetcher('[{"cycle": "1.30", "eol": "2027-04-30"}]');
        $collectors = (new NginxDetector('http://127.0.0.1:8080/nginx_status', self::requests('nginx/1.30.7'), new FakeProcessRunner(), true, $pages, $endOfLife))->detect()->instances[0]->collectors;
        self::assertNotNull($collectors);

        $collectors->get('nginx')?->collector->collect();
        $collectors->get('eol.nginx')?->collector->collect();

        self::assertSame(['http://127.0.0.1:8080/nginx_status'], $pages->urls);
        self::assertSame('https://endoflife.date/api/nginx.json', $endOfLife->url);
    }

    private static function detector(RequestStack $requests, ?FakeProcessRunner $runner = null): NginxDetector
    {
        return new NginxDetector(self::ENDPOINT, $requests, $runner ?? new FakeProcessRunner(), false, new FakeStatusPages());
    }

    private static function requests(string $software): RequestStack
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/jmonitor/stack', server: ['SERVER_SOFTWARE' => $software]));

        return $requests;
    }
}
