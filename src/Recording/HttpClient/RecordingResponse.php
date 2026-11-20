<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\HttpClient;

use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RecordingResponse implements ResponseInterface, StreamableInterface
{
    private bool $recorded = false;
    private bool $handled = false;

    /**
     * @param int $startedAt hrtime(true) when the call was made
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ResponseInterface $response,
        private readonly CallRecorder $calls,
        private readonly int $unit,
        private readonly string $url,
        private readonly int $startedAt,
    ) {}

    public function __destruct()
    {
        if ($this->recorded) {
            return;
        }

        try {
            // Waits for an unread response before recording it, so that the call is measured whole.
            if (!$this->handled && $this->response->getInfo('error') === null) {
                $this->response->getHeaders(true);
            }
        } finally {
            $this->record();
        }
    }

    public function getStatusCode(): int
    {
        $this->handled = true;

        try {
            return $this->response->getStatusCode();
        } catch (\Throwable $e) {
            $this->record();

            throw $e;
        }
    }

    public function getHeaders(bool $throw = true): array
    {
        $this->handled = true;

        try {
            return $this->response->getHeaders($throw);
        } catch (\Throwable $e) {
            $this->record();

            throw $e;
        }
    }

    public function getContent(bool $throw = true): string
    {
        $this->handled = true;

        try {
            return $this->response->getContent($throw);
        } finally {
            $this->record();
        }
    }

    public function toArray(bool $throw = true): array
    {
        $this->handled = true;

        try {
            return $this->response->toArray($throw);
        } finally {
            $this->record();
        }
    }

    public function cancel(): void
    {
        $this->handled = true;

        try {
            $this->response->cancel();
        } finally {
            $this->record();
        }
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    public function toStream(bool $throw = true)
    {
        $this->handled = true;

        try {
            if ($this->response instanceof StreamableInterface) {
                $stream = $this->response->toStream($throw);
            } else {
                if ($throw) {
                    $this->response->getHeaders(true);
                }
                $stream = StreamWrapper::createResource($this->response, $this->client);
            }
        } catch (\Throwable $e) {
            $this->record();

            throw $e;
        }

        // The context lives as long as the stream, and so does this response: the call is measured once the stream is released.
        try {
            stream_context_set_option($stream, 'jmonitor', 'response', $this);
        } catch (\Throwable $e) {
            $this->calls->logFailure($e);
        }

        return $stream;
    }

    /**
     * @param iterable<ResponseInterface> $responses
     *
     * @return \Generator<ResponseInterface, ChunkInterface>
     */
    public static function stream(HttpClientInterface $client, iterable $responses, ?float $timeout): \Generator
    {
        $recording = new \SplObjectStorage();
        $wrapped = [];
        foreach ($responses as $response) {
            if ($response instanceof self) {
                $response->handled = true;
                $recording[$response->response] = $response;
                $response = $response->response;
            }
            $wrapped[] = $response;
        }

        foreach ($client->stream($wrapped, $timeout) as $response => $chunk) {
            $recordingResponse = $recording[$response] ?? null;
            if ($recordingResponse === null) {
                yield $response => $chunk;

                continue;
            }

            if ($chunk instanceof LastChunk || ($chunk instanceof ErrorChunk && $response->getInfo('error') !== null)) {
                $recordingResponse->record();
            }

            yield $recordingResponse => $chunk;
        }
    }

    private function record(): void
    {
        if (!$this->recorded) {
            $this->recorded = true;
            $this->calls->record($this->unit, $this->response, $this->url, $this->startedAt);
        }
    }
}
