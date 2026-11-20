<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\HttpClient;

use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\HttpClient\CallRecorder;
use Jmonitor\JmonitorBundle\Recording\HttpClient\RecordingHttpClient;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RecordingHttpClientTest extends TestCase
{
    private BufferInterface $buffer;
    private Recorder $recorder;
    private AbstractLogger $logger;

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
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $level . ' ' . $message;
            }
        };
    }

    public function testAResponseReadToTheEndIsOneCall(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client = $this->client(new MockResponse('{"ok":true}', ['total_time' => 0.25]));

        static::assertSame('{"ok":true}', $client->request('GET', 'https://api.example.com/v1/items')->getContent());

        static::assertSame(['api.example.com' => ['n' => 1, 'm' => 250.0, 'd' => 250.0]], $this->calls($unit));
        static::assertSame(['http_ms' => 250.0, 'http_n' => 1], array_intersect_key($this->entry()->attributes, ['http_ms' => 0, 'http_n' => 0]));
    }

    public function testClientAndServerErrorsAreCountedApart(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client = $this->client([
            new MockResponse('missing', ['http_code' => 404, 'total_time' => 0.01]),
            new MockResponse('oops', ['http_code' => 500, 'total_time' => 0.02]),
            new MockResponse('moved', ['http_code' => 301, 'total_time' => 0.03]),
        ]);

        static::assertSame('missing', $client->request('GET', 'https://a.example/')->getContent(false));
        static::assertSame('oops', $client->request('GET', 'https://b.example/')->getContent(false));
        static::assertSame('moved', $client->request('GET', 'https://c.example/')->getContent(false));

        static::assertSame([
            'a.example' => ['n' => 1, 'm' => 10.0, 'c4' => 1, 'd' => 10.0],
            'b.example' => ['n' => 1, 'm' => 20.0, 'c5' => 1, 'd' => 20.0],
            'c.example' => ['n' => 1, 'm' => 30.0, 'd' => 30.0],
        ], $this->calls($unit));
    }

    public function testTheExceptionOfAServerErrorReachesTheCallerAndTheCallIsCounted(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $response = $this->client(new MockResponse('oops', ['http_code' => 500, 'total_time' => 0.02]))->request('GET', 'https://a.example/');

        try {
            $response->getContent();
            static::fail('The server error was swallowed.');
        } catch (ServerException $e) {
            static::assertSame(500, $e->getResponse()->getStatusCode());
        }

        static::assertSame(['a.example' => ['n' => 1, 'm' => 20.0, 'c5' => 1, 'd' => 20.0]], $this->calls($unit));
    }

    public function testATransportErrorIsANetworkFailure(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $response = $this->client(new MockResponse('', ['error' => 'Could not resolve host', 'total_time' => 0.005]))->request('GET', 'https://a.example/');

        try {
            $response->getContent();
            static::fail('The transport error was swallowed.');
        } catch (TransportExceptionInterface $e) {
            static::assertStringContainsString('Could not resolve host', $e->getMessage());
        }

        static::assertSame(['a.example' => ['n' => 1, 'm' => 5.0, 'cf' => 1, 'd' => 5.0]], $this->calls($unit));
    }

    public function testAResponseNeverReadIsCountedWhenDestroyedWithoutNewException(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client = $this->client(new MockResponse('ignored', ['total_time' => 0.04]));

        $response = $client->request('POST', 'https://hooks.example.com/notify');
        unset($response);

        static::assertSame(['hooks.example.com' => ['n' => 1, 'm' => 40.0, 'd' => 40.0]], $this->calls($unit));
    }

    public function testAnUnreadClientErrorThrowsOnDestructionLikeTheWrappedClient(): void
    {
        $bare = (new MockHttpClient(new MockResponse('', ['http_code' => 404])))->request('GET', 'https://a.example/');
        $thrown = null;
        try {
            unset($bare);
        } catch (ClientException $thrown) {
        }
        static::assertNotNull($thrown, 'The bare client no longer throws on destruction.');

        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $response = $this->client(new MockResponse('', ['http_code' => 404, 'total_time' => 0.01]))->request('GET', 'https://a.example/');
        $thrown = null;
        try {
            unset($response);
        } catch (ClientException $thrown) {
        }

        static::assertNotNull($thrown);
        static::assertSame(404, $thrown->getResponse()->getStatusCode());
        static::assertSame(['a.example' => ['n' => 1, 'm' => 10.0, 'c4' => 1, 'd' => 10.0]], $this->calls($unit));
    }

    public function testACallCancelledBeforeItsHeadersIsACallWithoutOutcome(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $response = $this->client(new MockResponse('late'))->request('GET', 'https://a.example/');

        $response->cancel();

        static::assertTrue($response->getInfo('canceled'));
        static::assertSame(0, $response->getInfo('http_code'));
        $call = $this->calls($unit)['a.example'];
        static::assertSame(['n' => 1], array_diff_key($call, ['m' => 0, 'd' => 0]));
        static::assertGreaterThan(0.0, $call['d']);
    }

    public function testACallCancelledAfterItsHeadersIsCountedByItsStatus(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client = $this->client([
            new MockResponse('ok', ['response_headers' => ['content-type' => 'text/plain'], 'total_time' => 0.01]),
            new MockResponse('oops', ['http_code' => 500, 'response_headers' => ['content-type' => 'text/plain'], 'total_time' => 0.02]),
        ]);

        foreach (['https://a.example/' => 200, 'https://b.example/' => 500] as $url => $status) {
            $response = $client->request('GET', $url);
            static::assertSame($status, $response->getStatusCode());
            $response->cancel();
            static::assertTrue($response->getInfo('canceled'));
        }

        static::assertSame([
            'a.example' => ['n' => 1, 'm' => 10.0, 'd' => 10.0],
            'b.example' => ['n' => 1, 'm' => 20.0, 'c5' => 1, 'd' => 20.0],
        ], $this->calls($unit));
    }

    public function testATransportErrorSeenByTheCallerThenCancelledStaysOneNetworkFailure(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $response = $this->client(new MockResponse('', ['error' => 'Could not resolve host', 'total_time' => 0.005]))->request('GET', 'https://a.example/');

        try {
            $response->getContent();
            static::fail('The transport error was swallowed.');
        } catch (TransportExceptionInterface) {
        }
        $response->cancel();

        static::assertTrue($response->getInfo('canceled'));
        static::assertSame(['a.example' => ['n' => 1, 'm' => 5.0, 'cf' => 1, 'd' => 5.0]], $this->calls($unit));
    }

    public function testStreamYieldsTheRecordingResponsesAndCountsEachOfThem(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client = $this->client([
            new MockResponse('first', ['total_time' => 0.01]),
            new MockResponse('', ['error' => 'Connection refused', 'total_time' => 0.02]),
            new MockResponse('third', ['http_code' => 201, 'total_time' => 0.03]),
        ]);
        $responses = [
            $client->request('GET', 'https://a.example/'),
            $client->request('GET', 'https://b.example/'),
            $client->request('GET', 'https://c.example/'),
        ];

        $seen = [];
        $content = [];
        foreach ($client->stream($responses) as $response => $chunk) {
            $index = array_search($response, $responses, true);
            static::assertIsInt($index, 'The stream yields a response the caller did not pass.');
            try {
                $content[$index] = ($content[$index] ?? '') . $chunk->getContent();
                if ($chunk->isLast()) {
                    $seen[$index] = 'done';
                }
            } catch (TransportExceptionInterface) {
                $seen[$index] = 'failed';
            }
        }

        ksort($seen);
        static::assertSame([0 => 'done', 1 => 'failed', 2 => 'done'], $seen);
        static::assertSame(['first', 'third'], [$content[0], $content[2]]);
        static::assertSame([
            'a.example' => ['n' => 1, 'm' => 10.0, 'd' => 10.0],
            'b.example' => ['n' => 1, 'm' => 20.0, 'cf' => 1, 'd' => 20.0],
            'c.example' => ['n' => 1, 'm' => 30.0, 'd' => 30.0],
        ], $this->calls($unit));
    }

    public function testStreamAcceptsTheResponsesOfTheWrappedClient(): void
    {
        $client = $this->client([new MockResponse('before'), new MockResponse('during', ['total_time' => 0.01])]);
        $before = $client->request('GET', 'https://before.example/');
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $during = $client->request('GET', 'https://during.example/');

        $content = [];
        foreach ($client->stream([$before, $during]) as $response => $chunk) {
            static::assertTrue($response === $before || $response === $during);
            $content[$response === $before ? 'before' : 'during'] = ($content[$response === $before ? 'before' : 'during'] ?? '') . $chunk->getContent();
        }

        static::assertSame(['before' => 'before', 'during' => 'during'], $content);
        static::assertSame(['during.example' => ['n' => 1, 'm' => 10.0, 'd' => 10.0]], $this->calls($unit));
    }

    public function testToStreamReadsTheBodyAndTheCallIsCounted(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client = $this->client([
            new MockResponse('streamed body', ['total_time' => 0.01]),
            new MockResponse('oops', ['http_code' => 503, 'total_time' => 0.02]),
            new MockResponse('detached body', ['http_code' => 404, 'total_time' => 0.03]),
        ]);

        $response = $client->request('GET', 'https://a.example/');
        $stream = $response->toStream();
        static::assertSame('streamed body', stream_get_contents($stream));
        unset($response, $stream);

        $failing = $client->request('GET', 'https://b.example/');
        try {
            $failing->toStream();
            static::fail('The server error was swallowed.');
        } catch (ServerException) {
        }

        $detached = $client->request('GET', 'https://c.example/')->toStream(false);
        static::assertSame('detached body', stream_get_contents($detached));
        unset($detached);

        static::assertSame([
            'a.example' => ['n' => 1, 'm' => 10.0, 'd' => 10.0],
            'b.example' => ['n' => 1, 'm' => 20.0, 'c5' => 1, 'd' => 20.0],
            'c.example' => ['n' => 1, 'm' => 30.0, 'c4' => 1, 'd' => 30.0],
        ], $this->calls($unit));
    }

    public function testAClientWithOptionsStillRecordsAndResolvesRelativeUrls(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client = $this->client(new MockResponse('ok', ['total_time' => 0.01]))->withOptions(['base_uri' => 'https://API.example.com:8443/v1/']);

        static::assertInstanceOf(RecordingHttpClient::class, $client);
        static::assertSame('ok', $client->request('GET', 'items?page=2')->getContent());

        static::assertSame(['api.example.com:8443' => ['n' => 1, 'm' => 10.0, 'd' => 10.0]], $this->calls($unit));
    }

    public function testResetReachesTheWrappedClientAndRecordingGoesOn(): void
    {
        $inner = new MockHttpClient(static fn(): MockResponse => new MockResponse('ok', ['total_time' => 0.01]));
        $client = new RecordingHttpClient($inner, new CallRecorder($this->recorder));

        $first = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client->request('GET', 'https://a.example/')->getContent();
        static::assertSame(1, $inner->getRequestsCount());
        static::assertSame(['a.example' => ['n' => 1, 'm' => 10.0, 'd' => 10.0]], $this->calls($first));

        $client->reset();
        $this->recorder->reset();
        static::assertSame(0, $inner->getRequestsCount());

        $second = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000001.0);
        $client->request('GET', 'https://b.example/')->getContent();
        static::assertSame(['b.example' => ['n' => 1, 'm' => 10.0, 'd' => 10.0]], $this->calls($second));
    }

    public function testAResponseThatOutlivesItsUnitCountsNowhere(): void
    {
        $client = $this->client([new MockResponse('kept', ['total_time' => 0.01]), new MockResponse('failed', ['http_code' => 500, 'total_time' => 0.01])]);
        $first = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $service = new \ArrayObject([
            'ok' => $client->request('GET', 'https://kept.example/'),
            'failed' => $client->request('GET', 'https://failed.example/'),
        ]);
        $this->recorder->end($first, 'GET app_first', 1.0);
        $this->recorder->reset();

        $second = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000001.0);
        static::assertSame('kept', $service['ok']->getContent());
        static::assertSame('failed', $service['failed']->getContent(false));
        $service->exchangeArray([]);
        $this->recorder->end($second, 'GET app_second', 1.0);

        static::assertCount(2, $this->buffer->entries);
        foreach ($this->buffer->entries as $entry) {
            static::assertSame([], $entry->children, $entry->key);
            static::assertArrayNotHasKey('http_n', $entry->attributes, $entry->key);
        }
    }

    public function testAnUnreadResponseDestroyedAfterItsUnitCountsNowhere(): void
    {
        $client = $this->client(new MockResponse('ignored', ['total_time' => 0.01]));
        $first = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $service = new \ArrayObject(['response' => $client->request('GET', 'https://kept.example/')]);
        $this->recorder->end($first, 'GET app_first', 1.0);
        $this->recorder->reset();

        $second = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000001.0);
        $service->exchangeArray([]);

        static::assertSame([], $this->calls($second));
    }

    public function testACallMadeOutsideAnyUnitIsLeftAlone(): void
    {
        $client = $this->client(new MockResponse('untouched', ['http_code' => 404]));

        $response = $client->request('GET', 'https://a.example/');
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);

        static::assertSame(404, $response->getStatusCode());
        static::assertSame('untouched', $response->getContent(false));
        unset($response);
        static::assertSame([], $this->calls($unit));
    }

    public function testADurationThatIsNotAFinitePositiveNumberFallsBackToTheElapsedTime(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client = $this->client([
            new MockResponse('a', ['total_time' => \NAN]),
            new MockResponse('b', ['total_time' => \INF]),
            new MockResponse('c', ['total_time' => -3.0]),
            new MockResponse('d', ['total_time' => 1e308]),
            new MockResponse('e', ['total_time' => 'soon']),
        ]);

        foreach (['a', 'b', 'c', 'd', 'e'] as $host) {
            $client->request('GET', 'https://' . $host . '.example/')->getContent();
        }

        $calls = $this->calls($unit);
        static::assertCount(5, $calls);
        foreach ($calls as $host => $call) {
            static::assertTrue(is_finite($call['d']) && $call['d'] >= 0.0 && $call['d'] < 1000.0, $host);
        }
        static::assertJson($this->entry()->toLine());
    }

    public function testARetriedCallLastsFromItsRequestToItsLastAttempt(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $inner = new RetryableHttpClient(
            new MockHttpClient([new MockResponse('busy', ['http_code' => 503, 'total_time' => 0.5]), new MockResponse('ok', ['total_time' => 0.01])]),
            new GenericRetryStrategy(delayMs: 50, jitter: 0.0),
            1,
        );
        $client = new RecordingHttpClient($inner, new CallRecorder($this->recorder, $this->logger));

        static::assertSame('ok', $client->request('GET', 'https://flaky.example.com/')->getContent());

        $calls = $this->calls($unit);
        static::assertSame(['flaky.example.com'], array_keys($calls));
        static::assertSame(['n', 'm', 'd'], array_keys($calls['flaky.example.com']));
        static::assertSame(1, $calls['flaky.example.com']['n']);
        static::assertGreaterThanOrEqual(50.0, $calls['flaky.example.com']['d']);
    }

    public function testOnlyTheHostIsKept(): void
    {
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
        $client = $this->client([
            new MockResponse('a'),
            new MockResponse('b'),
            new MockResponse('c'),
            new MockResponse('d', ['url' => 'not a url']),
        ]);

        $client->request('GET', 'https://user:pass@API.example.com:8443/secret?token=1')->getContent();
        $client->request('GET', 'http://[::1]/x')->getContent();
        $client->request('GET', 'https://example.com:443/')->getContent();
        $client->withOptions(['base_uri' => 'https://unread.example.com/'])->request('GET', 'path')->getContent();

        static::assertSame(['(unknown)', '[::1]', 'api.example.com:8443', 'example.com'], array_keys($this->calls($unit)));
        $line = $this->entry()->toLine();
        foreach (['secret', 'token', 'pass'] as $leak) {
            static::assertStringNotContainsString($leak, $line);
        }
    }

    public function testTheLoggerReachesAWrappedClientThatTakesOne(): void
    {
        $inner = new class (new MockHttpClient()) implements HttpClientInterface, LoggerAwareInterface {
            use DecoratorTrait;

            public ?LoggerInterface $logger = null;

            public function setLogger(LoggerInterface $logger): void
            {
                $this->logger = $logger;
            }
        };

        (new RecordingHttpClient($inner, new CallRecorder($this->recorder)))->setLogger($this->logger);
        (new RecordingHttpClient(new MockHttpClient(), new CallRecorder($this->recorder)))->setLogger($this->logger);

        static::assertSame($this->logger, $inner->logger);
    }

    public function testACallReadsTheInfoOfItsResponseOnce(): void
    {
        $response = new class implements ResponseInterface {
            /** @var array<string, mixed> */
            public array $info = [];
            public int $infoReads = 0;

            public function getStatusCode(): int
            {
                return 200;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return '';
            }

            public function toArray(bool $throw = true): array
            {
                return [];
            }

            public function cancel(): void {}

            public function getInfo(?string $type = null): mixed
            {
                ++$this->infoReads;

                return $type === null ? $this->info : $this->info[$type] ?? null;
            }
        };
        $inner = new class ($response) implements HttpClientInterface {
            use DecoratorTrait;

            public function __construct(private readonly ResponseInterface $response)
            {
                $this->client = new MockHttpClient();
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return $this->response;
            }
        };
        $client = new RecordingHttpClient($inner, new CallRecorder($this->recorder, $this->logger));
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);

        foreach ([
            ['http_code' => 404, 'error' => null, 'total_time' => 0.25, 'url' => 'https://a.example/x', 'response_headers' => ['HTTP/1.1 404 Not Found']],
            ['http_code' => 0, 'error' => 'Connection refused', 'total_time' => 0.01, 'url' => 'https://b.example/', 'response_headers' => []],
        ] as $info) {
            $response->info = $info;
            $response->infoReads = 0;
            $client->request('GET', $info['url'])->getContent();

            static::assertSame(1, $response->infoReads, $info['url']);
        }

        static::assertSame([
            'a.example' => ['n' => 1, 'm' => 250.0, 'c4' => 1, 'd' => 250.0],
            'b.example' => ['n' => 1, 'm' => 10.0, 'cf' => 1, 'd' => 10.0],
        ], $this->calls($unit));
        static::assertSame([], $this->logger->records);
    }

    public function testAFailureOfTheCaptureIsSwallowedAndLoggedOnce(): void
    {
        $response = new class implements ResponseInterface {
            public function getStatusCode(): int
            {
                return 200;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return 'body';
            }

            public function toArray(bool $throw = true): array
            {
                return [];
            }

            public function cancel(): void {}

            public function getInfo(?string $type = null): mixed
            {
                throw new \RuntimeException('Broken info');
            }
        };
        $inner = new class ($response) implements HttpClientInterface {
            use DecoratorTrait;

            public function __construct(private readonly ResponseInterface $response)
            {
                $this->client = new MockHttpClient();
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return $this->response;
            }
        };
        $client = new RecordingHttpClient($inner, new CallRecorder($this->recorder, $this->logger));
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);

        static::assertSame('body', $client->request('GET', 'https://a.example/')->getContent());
        static::assertSame('body', $client->request('GET', 'https://a.example/')->getContent());

        static::assertSame([], $this->calls($unit));
        static::assertSame(['error JMonitor could not record an outgoing HTTP call: Broken info'], $this->logger->records);
    }

    public function testAStreamThatTakesNoContextIsReturnedAsIsAndTheFailureLoggedOnce(): void
    {
        $closed = fopen('php://memory', 'r');
        \assert(\is_resource($closed));
        fclose($closed);
        $response = new class ($closed) implements ResponseInterface, StreamableInterface {
            /** @param resource $stream */
            public function __construct(private readonly mixed $stream) {}

            public function getStatusCode(): int
            {
                return 200;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return '';
            }

            public function toArray(bool $throw = true): array
            {
                return [];
            }

            public function cancel(): void {}

            public function getInfo(?string $type = null): mixed
            {
                return match ($type) {
                    null => ['http_code' => 200, 'url' => 'https://a.example/', 'total_time' => 0.01],
                    'http_code' => 200,
                    'url' => 'https://a.example/',
                    'total_time' => 0.01,
                    default => null,
                };
            }

            public function toStream(bool $throw = true)
            {
                return $this->stream;
            }
        };
        $inner = new class ($response) implements HttpClientInterface {
            use DecoratorTrait;

            public function __construct(private readonly ResponseInterface $response)
            {
                $this->client = new MockHttpClient();
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return $this->response;
            }
        };
        $client = new RecordingHttpClient($inner, new CallRecorder($this->recorder, $this->logger));
        $unit = $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);

        static::assertSame($closed, $client->request('GET', 'https://a.example/')->toStream());
        static::assertSame($closed, $client->request('GET', 'https://a.example/')->toStream());

        static::assertSame(['a.example' => ['n' => 2, 'm' => 10.0, 'd' => 20.0]], $this->calls($unit));
        static::assertCount(1, $this->logger->records);
        static::assertStringStartsWith('error JMonitor could not record an outgoing HTTP call: ', $this->logger->records[0]);
    }

    /**
     * @param ResponseInterface|list<ResponseInterface> $responses
     */
    private function client(ResponseInterface|array $responses): RecordingHttpClient
    {
        return new RecordingHttpClient(new MockHttpClient($responses), new CallRecorder($this->recorder, $this->logger));
    }

    /** @return array<string, array<string, int|float>> host => attributes of its child, plus its duration as `d` */
    private function calls(int $unit): array
    {
        $this->recorder->end($unit, 'GET app_test', 1.0);

        $calls = [];
        foreach ($this->entry()->children as $child) {
            if ($child->type === Entry::TYPE_HTTP) {
                $calls[$child->key] = $child->attributes + ['d' => $child->durationMs];
            }
        }
        ksort($calls);

        return $calls;
    }

    private function entry(): Entry
    {
        $entry = end($this->buffer->entries);
        static::assertInstanceOf(Entry::class, $entry, 'No unit was written.');

        return $entry;
    }
}
