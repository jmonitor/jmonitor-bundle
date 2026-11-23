<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use PHPUnit\Framework\TestCase;

final class FailureKindTest extends TestCase
{
    public function testAMissingPrerequisiteUnderAnUnreachableServerIsAMissingPrerequisite(): void
    {
        $failure = new UnreachableServerException('Not readable', 0, new MissingPrerequisiteException('composer: not found'));

        self::assertSame(FailureKind::MissingPrerequisite, FailureKind::of($failure));
    }

    public function testAnUnreachableServerAnywhereInTheChainIsUnreachable(): void
    {
        self::assertSame(FailureKind::Unreachable, FailureKind::of(new UnreachableServerException('Connection timed out')));
        self::assertSame(FailureKind::Unreachable, FailureKind::of(new UnreachableServerException('Connection timed out', 0, new \RuntimeException('Timed out'))));
        self::assertSame(FailureKind::Unreachable, FailureKind::of(new BootFailedException('Not readable', new \RuntimeException('Wrapped', 0, new UnreachableServerException('Connection refused')))));
    }

    public function testAnythingElseIsOther(): void
    {
        self::assertSame(FailureKind::Other, FailureKind::of(new \RuntimeException('Access denied')));
        self::assertSame(FailureKind::Other, FailureKind::of(new BootFailedException('Not readable', new \RuntimeException('Permission denied'))));
        self::assertSame(FailureKind::Other, FailureKind::of(new \Error('Call to undefined function opcache_get_status()')));
    }
}
