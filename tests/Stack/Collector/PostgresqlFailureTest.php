<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlActivityCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlFailure;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresqlFailureTest extends TestCase
{
    private const DRIVER = 'An exception occurred in the driver: SQLSTATE[08006] [7] ';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreachable(): iterable
    {
        yield 'two addresses tried' => [
            self::DRIVER . "connection to server at \"host.docker.internal\" (192.168.65.254), port 47002 failed: Connection refused\n"
            . "\tIs the server running on that host and accepting TCP/IP connections?\n"
            . "connection to server at \"host.docker.internal\" (fdc4:f303:9324::254), port 47002 failed: Network is unreachable\n"
            . "\tIs the server running on that host and accepting TCP/IP connections?\n",
            self::DRIVER . 'connection to server at "host.docker.internal" (192.168.65.254), port 47002 failed: Connection refused',
        ];
        yield 'one address tried' => [
            self::DRIVER . "connection to server at \"127.0.0.1\", port 1 failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?",
            self::DRIVER . 'connection to server at "127.0.0.1", port 1 failed: Connection refused',
        ];
        yield 'a socket' => [
            self::DRIVER . "connection to server on socket \"/var/run/postgresql/.s.PGSQL.5432\" failed: No such file or directory\n\tIs the server running locally and accepting connections on that socket?",
            self::DRIVER . 'connection to server on socket "/var/run/postgresql/.s.PGSQL.5432" failed: No such file or directory',
        ];
        yield 'a refused password' => [
            self::DRIVER . 'connection to server at "127.0.0.1", port 35432 failed: FATAL:  password authentication failed for user "jmonitor"',
            self::DRIVER . 'connection to server at "127.0.0.1", port 35432 failed: FATAL:  password authentication failed for user …',
        ];
    }

    #[DataProvider('unreachable')]
    public function testTheReasonOfAnUnreachableServerIsItsFirstAttempt(string $message, string $reason): void
    {
        $database = (new FakeDatabase())->failNext(new \RuntimeException($message));

        $failure = Failures::thrownBy(static fn() => (new PostgresqlActivityCollector(new DbalLink(static fn() => $database)))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame(FailureKind::Unreachable, FailureKind::of($failure));
        self::assertSame($reason, ErrorMessage::clean(ErrorMessage::of($failure)), 'the secrets are cleaned after');
    }

    public function testAFailureOfOneLineIsTheOneOfTheLink(): void
    {
        $unreachable = new UnreachableServerException(self::DRIVER . 'connection to server at "127.0.0.1", port 1 failed: Connection refused');

        self::assertSame($unreachable, PostgresqlFailure::of(new \RuntimeException('Wrapped', 0, $unreachable)));
    }
}
