<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\ApacheSoftwareCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\ApacheStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\ApacheDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeStatusPages;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ApacheDetectorTest extends TestCase
{
    private const ENDPOINT = 'http://localhost/server-status';

    public function testModPhpIsDetectedFromTheSapiWithoutReadingAnything(): void
    {
        $pages = new FakeStatusPages();
        $endOfLife = new FakeUrlFetcher(null);
        $detector = new ApacheDetector(self::ENDPOINT, new RequestStack(), true, 'apache2handler', $pages, $endOfLife);

        $detection = $detector->detect();

        self::assertSame(Component::Apache, $detector->component());
        self::assertTrue($detection->isFound());
        self::assertSame(Component::Apache, $detection->instances[0]->component);
        self::assertNull($detection->instances[0]->id);
        $collectors = $detection->instances[0]->collectors;
        self::assertNotNull($collectors);
        self::assertSame(['apache', 'apache.software', 'eol.apache-http-server'], $collectors->names());

        $status = $collectors->get('apache');
        self::assertNotNull($status);
        self::assertInstanceOf(ApacheStatusCollector::class, $status->collector);
        self::assertSame(Lifetime::Dynamic, $status->lifetime);
        self::assertSame(CostClass::Network, $status->cost);
        self::assertTrue($status->trackDelta);
        self::assertFalse($status->shared);

        $software = $collectors->get('apache.software');
        self::assertNotNull($software);
        self::assertInstanceOf(ApacheSoftwareCollector::class, $software->collector);
        self::assertSame(Lifetime::Dynamic, $software->lifetime);
        self::assertSame(CostClass::Immediate, $software->cost);
        self::assertFalse($software->trackDelta);
        self::assertFalse($software->shared);

        $support = $collectors->get('eol.apache-http-server');
        self::assertNotNull($support);
        self::assertInstanceOf(EndOfLifeCollector::class, $support->collector);
        self::assertSame(Lifetime::Day, $support->lifetime);
        self::assertSame(CostClass::Slow, $support->cost);
        self::assertFalse($support->trackDelta);
        self::assertTrue($support->shared);

        self::assertSame(0, $pages->calls);
        self::assertSame(0, $endOfLife->calls);
    }

    public function testPhpFpmBehindApacheIsDetectedFromTheServerSoftware(): void
    {
        $detection = (new ApacheDetector(self::ENDPOINT, self::requests('Apache/2.4.71 (Debian)'), false, 'fpm-fcgi', new FakeStatusPages()))->detect();

        self::assertTrue($detection->isFound());
    }

    public function testAnotherServerIsUnavailable(): void
    {
        $detection = (new ApacheDetector(self::ENDPOINT, self::requests('nginx/1.30.7'), false, 'fpm-fcgi', new FakeStatusPages()))->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('Apache is not serving this page.', $detection->error);
    }

    public function testWithoutMainRequestNothingIsDetected(): void
    {
        $detection = (new ApacheDetector(self::ENDPOINT, new RequestStack(), false, 'cli', new FakeStatusPages()))->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('Apache is not serving this page.', $detection->error);
    }

    public function testWithoutEndOfLifeThereIsNoSupportLookup(): void
    {
        $collectors = (new ApacheDetector(self::ENDPOINT, new RequestStack(), false, 'apache2handler', new FakeStatusPages()))->detect()->instances[0]->collectors;

        self::assertNotNull($collectors);
        self::assertSame(['apache', 'apache.software'], $collectors->names());
    }

    public function testTheCollectorsReadTheConfiguredEndpointAndTheSupportOfApache(): void
    {
        $pages = new FakeStatusPages();
        $endOfLife = new FakeUrlFetcher('[{"cycle": "2.4", "eol": "2030-12-31"}]');
        $collectors = (new ApacheDetector('http://127.0.0.1:8080/server-status', new RequestStack(), true, 'apache2handler', $pages, $endOfLife))->detect()->instances[0]->collectors;
        self::assertNotNull($collectors);

        $collectors->get('apache')?->collector->collect();
        $collectors->get('eol.apache-http-server')?->collector->collect();

        self::assertSame(['http://127.0.0.1:8080/server-status?auto'], $pages->urls);
        self::assertSame('https://endoflife.date/api/apache-http-server.json', $endOfLife->url);
    }

    public function testTheSoftwareCollectorGivesTheVersionOfTheMainRequest(): void
    {
        $collectors = (new ApacheDetector(self::ENDPOINT, self::requests('Apache/2.4.71 (Debian)'), false, 'fpm-fcgi', new FakeStatusPages()))->detect()->instances[0]->collectors;
        self::assertNotNull($collectors);

        self::assertSame(['version' => '2.4.71'], $collectors->get('apache.software')?->collector->collect());
    }

    private static function requests(string $software): RequestStack
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/jmonitor/stack', server: ['SERVER_SOFTWARE' => $software]));

        return $requests;
    }
}
