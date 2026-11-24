<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\ApacheSoftwareCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApacheSoftwareCollectorTest extends TestCase
{
    public function testTheVersionIsTheNumberTheServerSoftwareGives(): void
    {
        $collector = new ApacheSoftwareCollector('Apache/2.4.62 (Debian)', 'fpm-fcgi');

        self::assertSame(['version' => '2.4.62'], $collector->collect());
        self::assertSame('apache.software', $collector->getName());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function withoutVersion(): iterable
    {
        yield 'ServerTokens Prod' => ['Apache'];
        yield 'another server' => ['nginx/1.26.2'];
        yield 'nothing' => [null];
        yield 'a version of two numbers' => ['Apache/2.4'];
        yield 'a name that only ends with Apache' => ['NotApache/2.4.62'];
    }

    #[DataProvider('withoutVersion')]
    public function testWithoutAVersionThereIsNothingToGive(?string $software): void
    {
        self::assertSame([], (new ApacheSoftwareCollector($software, 'fpm-fcgi'))->collect());
    }

    public function testOutsideModPhpNothingIsAskedToApache(): void
    {
        self::assertSame([], (new ApacheSoftwareCollector(null, 'cli'))->collect());
    }

    public function testModPhpWithoutTheFunctionOfApacheGivesNothing(): void
    {
        if (function_exists('apache_get_version')) {
            self::markTestSkipped('The function of Apache exists.');
        }

        self::assertSame([], (new ApacheSoftwareCollector(null, 'apache2handler'))->collect());
    }
}
