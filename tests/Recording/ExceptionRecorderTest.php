<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\ExceptionRecorder;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class ExceptionRecorderTest extends KernelTestCase
{
    private BufferInterface $buffer;
    private Recorder $recorder;
    private ExceptionRecorder $exceptions;

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
        $this->exceptions = new ExceptionRecorder($this->recorder);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove(self::$varDirs);
        self::$varDirs = [];
    }

    public function testAnExceptionBehindAServerErrorIsAChildOfTheRequest(): void
    {
        $children = $this->request(500, [new \RuntimeException('Boom')]);

        static::assertCount(1, $children);
        static::assertSame(Entry::TYPE_EXCEPTION, $children[0]->type);
        static::assertSame(\RuntimeException::class, $children[0]->key);
        static::assertSame(1, $children[0]->attributes['n']);
    }

    public function testAnExceptionTurnedIntoAnotherResponseIsNotCounted(): void
    {
        static::assertSame([], $this->request(404, [new \RuntimeException('Not found')]));
        static::assertSame([], $this->request(200, [new \RuntimeException('Handled')]));
        static::assertSame([], $this->request(499, [new \RuntimeException('Handled')]));
    }

    public function testOnlyTheLastExceptionOfARequestIsCounted(): void
    {
        $children = $this->request(500, [new \LogicException('First'), new \RuntimeException('Last')]);

        static::assertCount(1, $children);
        static::assertSame(\RuntimeException::class, $children[0]->key);
        static::assertSame(1, $children[0]->attributes['n']);
    }

    public function testAnExceptionIsForgottenByTheNextRequest(): void
    {
        $this->request(404, [new \RuntimeException('Not found')]);

        static::assertSame([], $this->request(500));
    }

    public function testAnExceptionIsForgottenByAMainRequestThatDidNotTerminate(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');
        $this->exceptions->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $this->exceptions->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Fatal')));

        static::assertSame([], $this->request(500));
    }

    public function testAnExceptionIsForgottenOnReset(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $this->exceptions->onException(new ExceptionEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Boom')));
        $this->exceptions->reset();

        static::assertSame([], $this->request(500, onRequest: false));
    }

    public function testAnExceptionOfASubRequestCountsOnceWhenTheMainResponseIsAServerError(): void
    {
        $exception = new \RuntimeException('Boom');

        $children = $this->request(500, [$exception], [$exception]);

        static::assertCount(1, $children);
        static::assertSame(\RuntimeException::class, $children[0]->key);
        static::assertSame(1, $children[0]->attributes['n']);
        static::assertCount(1, $this->request(500, subRequestExceptions: [$exception]));
        static::assertSame([], $this->request(200, subRequestExceptions: [$exception]));
    }

    public function testAnAnonymousClassIsCountedUnderOneName(): void
    {
        $children = $this->request(500, [new class ('Boom') extends \RuntimeException {}]);

        static::assertCount(1, $children);
        static::assertSame('class@anonymous', $children[0]->key);
    }

    public function testTheCaptureCanBeSwitchedOff(): void
    {
        static::bootKernel(['jmonitor' => ['recording' => ['enabled' => true]]]);
        static::assertTrue(static::getContainer()->has(ExceptionRecorder::class));
        self::ensureKernelShutdown();

        static::bootKernel(['jmonitor' => ['recording' => ['enabled' => true, 'exceptions' => false]]]);
        static::assertFalse(static::getContainer()->has(ExceptionRecorder::class));
        static::assertFalse(static::getContainer()->getParameter('jmonitor.recording.exceptions'));
    }

    /**
     * @param list<\Throwable> $exceptions
     * @param list<\Throwable> $subRequestExceptions
     *
     * @return list<Entry> the children of the recorded request
     */
    private function request(int $status, array $exceptions = [], array $subRequestExceptions = [], bool $onRequest = true): array
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');

        if ($onRequest) {
            $this->exceptions->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        }
        $request->attributes->set(RequestRecorder::UNIT, $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true)));

        foreach ($subRequestExceptions as $exception) {
            $subRequest = Request::create('/_fragment');
            $this->exceptions->onRequest(new RequestEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST));
            $this->exceptions->onException(new ExceptionEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST, $exception));
        }
        foreach ($exceptions as $exception) {
            $this->exceptions->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception));
        }

        $this->exceptions->onTerminate(new TerminateEvent($kernel, $request, new Response('', $status)));
        $this->recorder->end($token, 'GET app_home', 1.0, ['status' => $status]);

        $entry = array_pop($this->buffer->entries);
        static::assertInstanceOf(Entry::class, $entry);

        return $entry->children;
    }

    /**
     * @param array{jmonitor?: array<string, mixed>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, $options['jmonitor'] ?? []);
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}
