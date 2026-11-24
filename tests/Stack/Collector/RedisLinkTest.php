<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\RedisInfoCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\RedisLink;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeRedis;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RedisPayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Response\Error;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\Exception\InvalidArgumentException;

final class RedisLinkTest extends TestCase
{
    private const UNREACHABLE = 'The Redis server cannot be reached: the connection is refused, cannot be opened or gets no answer in time.';
    private const PASSWORD = 'The Redis server asks for a password, or refuses the one given.';
    private const NO_INFO = 'The Redis server did not answer INFO.';
    private const CLIENT_FAILED = 'The Redis client failed while reading the server.';
    private const DENIED = 'The Redis user of this connection may not run INFO, or may not select its database.';
    private const UNREADABLE_DSN = 'The DSN of this connection cannot be read.';
    private const NO_CLIENT = 'Reading Redis needs the redis extension or the predis/predis package.';

    private int $connects = 0;

    public function testItAsksForItsClientAtTheFirstCommandOnly(): void
    {
        $redis = new FakeRedis(RedisPayloads::info());
        $link = $this->link($redis);

        self::assertSame(0, $this->connects);

        $link->info();
        $link->info();

        self::assertSame(1, $this->connects);
        self::assertSame(2, $redis->infos);
    }

    public function testTheFlatInfoOfTheExtensionIsGivenAsItIs(): void
    {
        self::assertSame(RedisPayloads::info(), $this->link(new FakeRedis(RedisPayloads::info()))->info());
    }

    public function testTheSectionsOfPredisAreFlattened(): void
    {
        $info = $this->link(new FakeRedis(RedisPayloads::sections()))->info();
        $expected = array_map(strval(...), RedisPayloads::info());
        ksort($info);
        ksort($expected);

        self::assertSame($expected, $info);
    }

    public function testOnceTheServerCannotBeReachedEveryCallFailsAtOnce(): void
    {
        $redis = new FakeRedis(new InvalidArgumentException('Redis connection failed: Connection refused'));
        $link = $this->link($redis);

        $first = Failures::thrownBy(static fn() => $link->info());
        $second = Failures::thrownBy(static fn() => $link->info());

        self::assertInstanceOf(UnreachableServerException::class, $first);
        self::assertSame(self::UNREACHABLE, $first->getMessage());
        self::assertSame($first, $second);
        self::assertSame(1, $redis->infos);
    }

    public function testAClientThatCannotBeBuiltIsNotAskedTwice(): void
    {
        $link = new RedisLink(function (): never {
            ++$this->connects;

            throw new InvalidArgumentException('Invalid Redis DSN: missing host.');
        });

        $first = Failures::thrownBy(static fn() => $link->info());
        Failures::thrownBy(static fn() => $link->info());

        self::assertSame(\RuntimeException::class, $first::class);
        self::assertSame(self::UNREADABLE_DSN, $first->getMessage());
        self::assertSame(FailureKind::Other, FailureKind::of($first));
        self::assertSame(1, $this->connects);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedPasswords(): iterable
    {
        yield 'NOAUTH, extension' => [new InvalidArgumentException('Redis connection failed: NOAUTH Authentication required.')];
        yield 'WRONGPASS, extension' => [new InvalidArgumentException("Redis connection failed: Could not authenticate 'WRONGPASS invalid username-password pair or user is disabled.'")];
        yield 'NOAUTH, Predis' => [new Error('NOAUTH Authentication required.')];
        yield 'WRONGPASS, Predis 3' => [new \RuntimeException('Failed: WRONGPASS invalid username-password pair or user is disabled. [tcp://127.0.0.1:6380]')];
        yield 'WRONGPASS, Predis 2' => [new \RuntimeException('`AUTH` failed: WRONGPASS invalid username-password pair or user is disabled. [tcp://127.0.0.1:6380]')];
    }

    #[DataProvider('refusedPasswords')]
    public function testARefusedPasswordIsUnreachable(mixed $answer): void
    {
        $failure = Failures::thrownBy(fn() => $this->link(new FakeRedis($answer))->info());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame(self::PASSWORD, $failure->getMessage());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function deniedInfos(): iterable
    {
        yield 'extension' => [new \RuntimeException("NOPERM User limited has no permissions to run the 'info' command")];
        yield 'Predis' => [new Error("NOPERM User limited has no permissions to run the 'info' command")];
    }

    #[DataProvider('deniedInfos')]
    public function testADeniedInfoIsAMissingPrerequisite(mixed $answer): void
    {
        $failure = Failures::thrownBy(fn() => $this->link(new FakeRedis($answer))->info());

        self::assertInstanceOf(MissingPrerequisiteException::class, $failure);
        self::assertSame(self::DENIED, $failure->getMessage());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function otherAnswers(): iterable
    {
        yield 'false' => [false, self::NO_INFO];
        yield 'null' => [null, self::NO_INFO];
        yield 'a string' => ['OK', self::NO_INFO];
        yield 'an empty array' => [[], self::NO_INFO];
        yield 'another error' => [new Error('LOADING Redis is loading the dataset in memory'), self::NO_INFO];
        yield 'a timeout' => [new \RuntimeException('Operation has timed out'), self::UNREACHABLE];
        yield 'a type error' => [new \TypeError('Redis::info(): Return value must be of type array, bool returned'), self::CLIENT_FAILED];
        yield 'an error' => [new \Error('Call to a member function read() on null'), self::CLIENT_FAILED];
    }

    #[DataProvider('otherAnswers')]
    public function testAnyOtherAnswerIsUnreachable(mixed $answer, string $reason): void
    {
        $failure = Failures::thrownBy(fn() => $this->link(new FakeRedis($answer))->info());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame($reason, $failure->getMessage());
    }

    public function testAnErrorOfTheClientIsCaught(): void
    {
        $failure = Failures::thrownBy(fn() => $this->link(new FakeRedis(new \TypeError('Redis::info(): Return value must be of type array, bool returned')))->info());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame(self::CLIENT_FAILED, $failure->getMessage());
    }

    public function testWithoutAnyClientItIsAMissingPrerequisite(): void
    {
        $link = new RedisLink(static fn(): object => throw new CacheException('Cannot find the "redis" extension nor the "relay" extension nor the "predis/predis" package.'));

        $failure = Failures::thrownBy(static fn() => $link->info());

        self::assertInstanceOf(MissingPrerequisiteException::class, $failure);
        self::assertSame(self::NO_CLIENT, $failure->getMessage());
    }

    public function testItNeverSendsConfig(): void
    {
        $redis = new FakeRedis(RedisPayloads::info());

        (new RedisInfoCollector($this->link($redis)))->collect();

        self::assertSame(1, $redis->infos);
        self::assertSame(0, $redis->configs);
    }

    public function testItClosesWhatItOpened(): void
    {
        $redis = new FakeRedis(RedisPayloads::info());
        $link = $this->link($redis);

        $link->release();
        self::assertSame(0, $redis->closes);

        $link->info();
        $link->release();
        $link->release();

        self::assertSame(1, $redis->closes);

        $link->info();
        $link->release();

        self::assertSame(2, $this->connects);
        self::assertSame(2, $redis->closes);
    }

    public function testADeniedInfoClosesItsConnection(): void
    {
        $redis = new FakeRedis(new Error('NOPERM User limited has no permissions'));
        $link = $this->link($redis);

        Failures::thrownBy(static fn() => $link->info());
        $link->release();

        self::assertSame(1, $redis->closes);
    }

    public function testADeniedSelectDoesNotOpenAConnectionToCloseIt(): void
    {
        $redis = new FakeRedis(new \RuntimeException("NOPERM User monitor has no permissions to run the 'select' command"));
        $link = $this->link($redis);

        $failure = Failures::thrownBy(static fn() => $link->info());
        $link->release();

        self::assertInstanceOf(MissingPrerequisiteException::class, $failure);
        self::assertSame(self::DENIED, $failure->getMessage());
        self::assertSame(0, $redis->closes);
    }

    public function testAConnectionThatFailedIsNotClosedByHand(): void
    {
        $redis = new FakeRedis(new InvalidArgumentException('Redis connection failed: Connection refused'));
        $link = $this->link($redis);

        Failures::thrownBy(static fn() => $link->info());
        $link->release();

        self::assertSame(0, $redis->closes);
    }

    public function testPredisIsDisconnected(): void
    {
        $client = new class {
            public int $disconnects = 0;

            /** @return array<string, mixed> */
            public function info(): array
            {
                return RedisPayloads::sections();
            }

            public function disconnect(): void
            {
                ++$this->disconnects;
            }
        };
        $link = new RedisLink(static fn(): object => $client);

        $link->info();
        $link->release();

        self::assertSame(1, $client->disconnects);
    }

    public function testAClosingThatFailsIsIgnored(): void
    {
        $client = new class {
            /** @return array<string, mixed> */
            public function info(): array
            {
                return RedisPayloads::info();
            }

            public function close(): never
            {
                throw new \RuntimeException('Connection lost');
            }
        };
        $link = new RedisLink(static fn(): object => $client);
        $link->info();

        $link->release();

        $this->addToAssertionCount(1);
    }

    public function testAWarningOfTheClientReachesNoOne(): void
    {
        $client = new class {
            public function info(): never
            {
                trigger_error('stream_socket_enable_crypto(): SSL: Handshake timed out', \E_USER_WARNING);

                throw new \RuntimeException('Error while switching to encrypted communication [tls://127.0.0.1:6379]');
            }
        };
        $link = new RedisLink(static fn(): object => $client);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $failure = Failures::thrownBy(static fn() => $link->info());
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertInstanceOf(UnreachableServerException::class, $failure);
    }

    public function testNoFailureCarriesTheDsn(): void
    {
        $answers = [
            new InvalidArgumentException('Redis connection failed: php_network_getaddresses: getaddrinfo for jm-host.invalid failed [tcp://jm-user:jm-secret@jm-host.invalid:6390]'),
            new \RuntimeException('`AUTH` failed: WRONGPASS invalid username-password pair [tcp://jm-host.invalid:6390]'),
            new Error("NOPERM User jm-user has no permissions to run the 'info' command"),
            new \RuntimeException("NOPERM User jm-user has no permissions to run the 'info' command"),
            new \TypeError('redis://jm-user:jm-secret@jm-host.invalid:6390'),
        ];

        foreach ($answers as $answer) {
            $failure = Failures::thrownBy(fn() => $this->link(new FakeRedis($answer))->info());

            self::assertDoesNotMatchRegularExpression('/jm-|6390/', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    private function link(FakeRedis $redis): RedisLink
    {
        return new RedisLink(function () use ($redis): FakeRedis {
            ++$this->connects;

            return $redis;
        });
    }
}
