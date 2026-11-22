<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use PHPUnit\Framework\TestCase;

final class ErrorMessageTest extends TestCase
{
    public function testAPasswordAndItsUserLeaveAnUrl(): void
    {
        $message = ErrorMessage::clean('Cannot reach mysql://app:s3cret@db:3306/x');

        self::assertSame('Cannot reach mysql://db:3306/x', $message);
        self::assertStringNotContainsString('s3cret', $message);
        self::assertStringNotContainsString('app', $message);
    }

    public function testAUserAloneLeavesAnUrl(): void
    {
        self::assertSame('Cannot reach redis://cache', ErrorMessage::clean('Cannot reach redis://default@cache'));
    }

    public function testAPasswordHoldingAnAtSignLeavesAsWell(): void
    {
        self::assertSame('mysql://db/x and mail admin@example.com', ErrorMessage::clean('mysql://app:p@ss@db/x and mail admin@example.com'));
    }

    public function testAMessageWithoutUrlIsUnchanged(): void
    {
        self::assertSame('Access denied for user root@localhost', ErrorMessage::clean('Access denied for user root@localhost'));
    }

    public function testTheCauseFollowsTheMessage(): void
    {
        $e = new \RuntimeException('SHOW GLOBAL STATUS is not accessible', 0, new \RuntimeException('Connection refused'));

        self::assertSame('SHOW GLOBAL STATUS is not accessible (Connection refused)', ErrorMessage::of($e));
        self::assertSame('Connection refused', ErrorMessage::of(new \RuntimeException('Connection refused')));
    }

    public function testTheCauseIsAddedOnlyOnce(): void
    {
        $same = new \RuntimeException('Connection refused', 0, new \RuntimeException('Connection refused'));
        $quoted = new \RuntimeException('Cannot reach the server: Connection refused', 0, new \RuntimeException('Connection refused'));

        self::assertSame('Connection refused', ErrorMessage::of($same));
        self::assertSame('Cannot reach the server: Connection refused', ErrorMessage::of($quoted));
    }

    public function testALongMessageIsCutToFiveHundredCharacters(): void
    {
        $message = ErrorMessage::clean(str_repeat('a', 498) . 'é' . str_repeat('b', 2501));

        self::assertSame(ErrorMessage::MAX_LENGTH, mb_strlen($message));
        self::assertStringEndsWith('…', $message);
        self::assertTrue(mb_check_encoding($message, 'UTF-8'));
    }
}
