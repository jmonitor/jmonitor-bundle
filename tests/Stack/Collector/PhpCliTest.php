<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpCli;
use PHPUnit\Framework\TestCase;

final class PhpCliTest extends TestCase
{
    public function testItIsThePhpOfTheCommandLine(): void
    {
        self::assertSame(['/usr/local/bin/php'], (new PhpCli(static fn(): string => '/usr/local/bin/php'))->command());
        self::assertSame([\PHP_BINARY], (new PhpCli())->command(), 'found by default the way Symfony finds it');
    }

    public function testWithoutAnyItIsAMissingPrerequisite(): void
    {
        $this->expectException(MissingPrerequisiteException::class);
        $this->expectExceptionMessage('No PHP binary of the command line was found.');

        (new PhpCli(static fn(): false => false))->command();
    }
}
