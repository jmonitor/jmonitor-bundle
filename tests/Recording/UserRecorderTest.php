<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Recording\UserRecorder;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserRecorderTest extends KernelTestCase
{
    private BufferInterface $buffer;
    private Recorder $recorder;
    private TokenStorage $tokens;
    private AbstractLogger $logger;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->buffer = new class implements BufferInterface {
            /** @var list<Entry> */
            public array $entries = [];

            public function append(Entry $entry): void
            {
                $this->entries[] = $entry;
            }

            public function drain(callable $consumer, bool $includeCurrent = false): int
            {
                return 0;
            }

            public function isUnderPressure(): bool
            {
                return false;
            }
        };
        $this->recorder = new Recorder($this->buffer);
        $this->tokens = new TokenStorage();
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $level . ' ' . $message;
            }
        };
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove(self::$varDirs);
        self::$varDirs = [];
    }

    public function testASignedInUserIsAChildOfTheRequest(): void
    {
        $this->tokens->setToken(self::token('admin'));

        $children = $this->request();

        static::assertCount(1, $children);
        static::assertSame(Entry::TYPE_USER, $children[0]->type);
        static::assertSame('admin', $children[0]->key);
        static::assertSame(1, $children[0]->attributes['n']);
    }

    public function testAnAnonymousRequestHasNoUser(): void
    {
        static::assertSame([], $this->request());

        $this->tokens->setToken(new NullToken());
        static::assertSame([], $this->request());
    }

    public function testASubRequestIsNotCounted(): void
    {
        $this->tokens->setToken(self::token('admin'));

        static::assertSame([], $this->request(HttpKernelInterface::SUB_REQUEST));
    }

    public function testAnEmptyIdentifierIsIgnored(): void
    {
        $this->tokens->setToken(self::token(''));

        static::assertSame([], $this->request());
    }

    public function testALongIdentifierIsCut(): void
    {
        $this->tokens->setToken(self::token(str_repeat('a', 10_000)));

        $children = $this->request();

        static::assertCount(1, $children);
        static::assertSame(str_repeat('a', 190), $children[0]->key);
    }

    public function testAnIdentifierWithInvalidBytesBecomesValidUtf8(): void
    {
        $this->tokens->setToken(self::token("\xff\xfead\xc3min\xc3"));

        $children = $this->request();

        static::assertCount(1, $children);
        static::assertTrue(mb_check_encoding($children[0]->key, 'UTF-8'));
        static::assertSame('??ad?min?', $children[0]->key);
    }

    public function testHostileIdentifiersKeepTheirOwnRowAndTheRegisterIsWritten(): void
    {
        $identifiers = ['<script>alert(1)</script>', "\xff\xfeadmin", str_repeat('é', 10_000)];
        $entries = [];
        foreach ($identifiers as $identifier) {
            $this->tokens->setToken(self::token($identifier));
            $entries[] = $this->unit();
        }

        $dir = sys_get_temp_dir() . '/jmonitor-user-recorder-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            $registry = new HotKeys(new BufferDirectory($dir, 'app'), new MockClock('@1700000000'));
            $aggregates = (new Aggregator(1000, $registry))->aggregate($entries);
            $registry->commit();
            $registered = (new HotKeys(new BufferDirectory($dir, 'app'), new MockClock('@1700000000')))->keys(Family::User);
        } finally {
            (new Filesystem())->remove($dir);
        }

        $keys = [];
        foreach ($aggregates as $aggregate) {
            if ($aggregate->period === Period::OneHour && $aggregate->type === Metric::USER_DURATION) {
                $keys[] = $aggregate->key;
            }
        }
        sort($keys);
        sort($registered);

        static::assertCount(3, $keys);
        static::assertNotContains(Aggregator::OTHER, $keys);
        static::assertSame($keys, $registered);
        foreach ($keys as $key) {
            static::assertTrue(mb_check_encoding($key, 'UTF-8'));
            static::assertLessThanOrEqual(255, \strlen($key));
        }
    }

    public function testAFailingUserIsSwallowedAndLoggedOnce(): void
    {
        $this->tokens->setToken(self::token('admin', new \RuntimeException('User backend down')));
        $users = new UserRecorder($this->recorder, $this->tokens, $this->logger);

        static::assertSame([], $this->request(users: $users));
        static::assertSame([], $this->request(users: $users));
        static::assertSame(['error JMonitor could not record the user of a request: User backend down'], $this->logger->records);
    }

    public function testAPendingAuthenticationIsNeverTriggered(): void
    {
        $initialized = 0;
        $this->tokens->setInitializer(function () use (&$initialized): void {
            ++$initialized;
            $this->tokens->setToken(self::token('admin'));
        });

        static::assertSame([], $this->request());
        static::assertSame(0, $initialized);

        $this->tokens->getToken();
        $children = $this->request();

        static::assertSame(1, $initialized);
        static::assertCount(1, $children);
        static::assertSame('admin', $children[0]->key);
    }

    public function testAStorageOfAnotherClassIsNotReadAndLoggedOnce(): void
    {
        $tokens = new class implements TokenStorageInterface {
            public int $reads = 0;

            public function getToken(): ?TokenInterface
            {
                ++$this->reads;

                throw new \LogicException('This storage must not be read.');
            }

            public function setToken(?TokenInterface $token): void {}
        };

        $users = new UserRecorder($this->recorder, $tokens, $this->logger);

        static::assertSame([], $this->request(users: $users));
        static::assertSame([], $this->request(users: $users));
        static::assertSame(0, $tokens->reads);
        static::assertSame([sprintf('warning JMonitor does not record users: the state of the token storage "%s" cannot be read.', get_debug_type($tokens))], $this->logger->records);
    }

    public function testASymfonyStorageLogsNothing(): void
    {
        $users = new UserRecorder($this->recorder, $this->tokens, $this->logger);

        static::assertSame([], $this->request(users: $users));
        $this->tokens->setToken(self::token('admin'));
        static::assertCount(1, $this->request(users: $users));
        static::assertSame([], $this->logger->records);
    }

    public function testTheCaptureCanBeSwitchedOff(): void
    {
        static::bootKernel(['jmonitor' => ['recording' => ['enabled' => true]]]);
        static::assertTrue(static::getContainer()->has(UserRecorder::class));
        static::assertTrue(static::getContainer()->getParameter('jmonitor.recording.users'));
        self::ensureKernelShutdown();

        static::bootKernel(['jmonitor' => ['recording' => ['enabled' => true, 'users' => false]]]);
        static::assertFalse(static::getContainer()->has(UserRecorder::class));
        static::assertFalse(static::getContainer()->getParameter('jmonitor.recording.users'));
        self::ensureKernelShutdown();

        static::bootKernel(['jmonitor' => ['recording' => ['enabled' => true]], 'security' => false]);
        static::assertFalse(static::getContainer()->has(UserRecorder::class));
    }

    /**
     * @return list<Entry> the children of the recorded request
     */
    private function request(int $type = HttpKernelInterface::MAIN_REQUEST, ?UserRecorder $users = null): array
    {
        return $this->unit($type, $users)->children;
    }

    private function unit(int $type = HttpKernelInterface::MAIN_REQUEST, ?UserRecorder $users = null): Entry
    {
        $users ??= new UserRecorder($this->recorder, $this->tokens, $this->logger);
        $request = Request::create('/');
        $request->attributes->set(RequestRecorder::UNIT, $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true)));

        $users->onResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, new Response()));
        $this->recorder->end($token, 'GET app_home', 1.0, ['status' => 200]);

        $entry = array_pop($this->buffer->entries);
        static::assertInstanceOf(Entry::class, $entry);

        return $entry;
    }

    private static function token(string $identifier, ?\Throwable $failure = null): UsernamePasswordToken
    {
        $user = new class ($identifier, $failure) implements UserInterface {
            public function __construct(
                private readonly string $identifier,
                private readonly ?\Throwable $failure,
            ) {}

            public function getUserIdentifier(): string
            {
                return $this->failure === null ? $this->identifier : throw $this->failure;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void {}
        };

        return new UsernamePasswordToken($user, 'main');
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, security?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, $options['jmonitor'] ?? [], withSecurity: $options['security'] ?? true);
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}
