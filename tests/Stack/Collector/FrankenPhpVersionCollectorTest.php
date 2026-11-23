<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\FrankenPhpVersionCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class FrankenPhpVersionCollectorTest extends TestCase
{
    public function testItReadsTheVersion(): void
    {
        $runner = (new FakeProcessRunner())->answer(['/usr/local/bin/frankenphp', 'version'], "FrankenPHP v1.13.0 PHP 8.4.26 Caddy v2.11.7 h1:yj0Y4fYZGPkSvibBJ1sTWE33xC0fxztVyXEW5iIdUT4=\n");
        $collector = new FrankenPhpVersionCollector($runner, '/usr/local/bin/frankenphp');

        self::assertSame(['version' => '1.13.0'], $collector->collect());
        self::assertSame('frankenphp.version', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public function testAnUnrecognisedOutputIsAFailureWithoutTheOutput(): void
    {
        $runner = (new FakeProcessRunner())->answer(['frankenphp', 'version'], 'token=abc123 something else');

        try {
            (new FrankenPhpVersionCollector($runner, 'frankenphp'))->collect();
            self::fail('Nothing was thrown.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(MissingPrerequisiteException::class, $e);
            self::assertStringNotContainsString('abc123', $e->getMessage());
        }
    }

    public function testWithoutTheBinaryItIsAMissingPrerequisite(): void
    {
        $runner = (new FakeProcessRunner())->answer(['frankenphp', 'version'], 'sh: frankenphp: not found', 127);

        $this->expectException(MissingPrerequisiteException::class);

        (new FrankenPhpVersionCollector($runner, 'frankenphp'))->collect();
    }

    public function testTheCommandIsBoundToThreeSeconds(): void
    {
        $runner = (new FakeProcessRunner())->answer(['frankenphp', 'version'], 'FrankenPHP v1.13.0 PHP 8.4.26 Caddy v2.11.7');

        (new FrankenPhpVersionCollector($runner, 'frankenphp'))->collect();

        self::assertSame([['command' => ['frankenphp', 'version'], 'working_directory' => '/', 'timeout' => 3, 'env' => []]], $runner->calls);
    }
}
