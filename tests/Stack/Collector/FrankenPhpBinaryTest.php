<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\FrankenPhpBinary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrankenPhpBinaryTest extends TestCase
{
    public function testTheRunningFrankenPhpIsTheOneAskedForItsVersion(): void
    {
        self::assertSame('/usr/local/bin/frankenphp', FrankenPhpBinary::path('/usr/local/bin/frankenphp'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherProcesses(): iterable
    {
        yield 'PHP-FPM' => ['/usr/local/sbin/php-fpm'];
        yield 'Apache' => ['/usr/sbin/apache2'];
        yield 'unreadable' => [''];
    }

    #[DataProvider('otherProcesses')]
    public function testAnotherProcessLeavesTheFrankenPhpOfThePath(string $self): void
    {
        self::assertSame('frankenphp', FrankenPhpBinary::path($self));
    }

    public function testTheProcessOfTheTestsIsNotFrankenPhp(): void
    {
        error_clear_last();

        self::assertSame('frankenphp', FrankenPhpBinary::path());
        self::assertNull(error_get_last());
    }
}
