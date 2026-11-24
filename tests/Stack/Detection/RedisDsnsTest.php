<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RedisDsnsTest extends TestCase
{
    public function testItKeepsTheConfiguredAndTheFoundEntriesInTheirOrder(): void
    {
        $dsns = new RedisDsns(new ServiceLocator([]), ['main', 'sessions'], ['.cache_connection.a', 'session.abstract_handler', RedisDsns::ENVIRONMENT]);

        self::assertSame(['main', 'sessions'], $dsns->configured());
        self::assertSame(['.cache_connection.a', 'session.abstract_handler', RedisDsns::ENVIRONMENT], $dsns->found());
    }

    public function testEachEntryIsReadToItsTarget(): void
    {
        $dsns = self::dsns(['main' => 'redis://cache:6380/2', 'socket' => 'redis:///var/run/redis.sock']);

        self::assertSame('cache:6380', $dsns->target('main')->server()->key);
        self::assertSame('socket:/var/run/redis.sock', $dsns->target('socket')->server()->key);
    }

    public function testAMissingEnvironmentVariableOnlyBreaksItsEntry(): void
    {
        $dsns = new RedisDsns(new ServiceLocator([
            'main' => static fn(): \ArrayObject => new \ArrayObject(['redis://cache']),
            'broken' => static fn() => throw new EnvNotFoundException('Environment variable not found: "CACHE_URL".'),
            'unset' => static fn(): \ArrayObject => new \ArrayObject([null]),
        ]), ['main', 'broken', 'unset'], []);

        $broken = Failures::thrownBy(static fn() => $dsns->target('broken'));
        $unset = Failures::thrownBy(static fn() => $dsns->target('unset'));

        self::assertSame('cache:6379', $dsns->target('main')->server()->key);
        self::assertInstanceOf(\RuntimeException::class, $broken);
        self::assertSame('The DSN of this connection cannot be read: an environment variable is missing ("CACHE_URL").', $broken->getMessage());
        self::assertNull($broken->getPrevious());
        self::assertInstanceOf(\RuntimeException::class, $unset);
        self::assertSame('This connection has no DSN.', $unset->getMessage());
    }

    public function testAnEntryThatGivesNoDsnCannotBeRead(): void
    {
        $dsns = new RedisDsns(new ServiceLocator([
            'string' => static fn(): string => 'redis://cache',
            'array' => static fn(): \ArrayObject => new \ArrayObject([['redis://cache']]),
            'failing' => static fn() => throw new \LogicException('redis://user:s3cr3t@cache'),
            'empty' => static fn(): \ArrayObject => new \ArrayObject(['']),
        ]), ['string', 'array', 'failing', 'empty'], []);

        foreach (['string', 'array', 'failing'] as $entry) {
            $failure = Failures::thrownBy(static fn() => $dsns->target($entry));

            self::assertInstanceOf(\RuntimeException::class, $failure, $entry);
            self::assertSame('The DSN of this connection cannot be read.', $failure->getMessage(), $entry);
            self::assertNull($failure->getPrevious(), $entry);
        }
        self::assertSame('This connection has no DSN.', Failures::thrownBy(static fn() => $dsns->target('empty'))->getMessage());
    }

    public function testAnEntryIsReadOncePerRequestAndForgottenOnReset(): void
    {
        $reads = 0;
        $dsns = new RedisDsns(new ServiceLocator([
            'main' => static function () use (&$reads): \ArrayObject {
                ++$reads;

                return new \ArrayObject(['redis://cache']);
            },
        ]), ['main'], []);

        $dsns->target('main');
        $dsns->target('main');
        $dsns->reset();
        $dsns->target('main');

        self::assertSame(2, $reads);
    }

    public function testAFailureIsKeptUntilReset(): void
    {
        $reads = 0;
        $dsns = new RedisDsns(new ServiceLocator([
            'main' => static function () use (&$reads): \ArrayObject {
                ++$reads;

                return new \ArrayObject(['memcached://cache']);
            },
        ]), ['main'], []);

        Failures::thrownBy(static fn() => $dsns->target('main'));
        $failure = Failures::thrownBy(static fn() => $dsns->target('main'));

        self::assertSame('This DSN is not a Redis DSN.', $failure->getMessage());
        self::assertSame(1, $reads);
    }

    /**
     * @param array<string, string> $entries
     */
    private static function dsns(array $entries): RedisDsns
    {
        return new RedisDsns(
            new ServiceLocator(array_map(static fn(string $dsn): \Closure => static fn(): \ArrayObject => new \ArrayObject([$dsn]), $entries)),
            array_keys($entries),
            [],
        );
    }
}
