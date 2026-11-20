<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App;

use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpResponses
{
    public const FLAKY_BASE_URI = 'https://flaky.example.com/';

    private int $flakyCalls = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        if (str_starts_with($url, self::FLAKY_BASE_URI) && $this->flakyCalls++ % 2 === 0) {
            return new MockResponse('Busy', ['http_code' => 503]);
        }

        return new MockResponse('{"ok":true}', ['response_headers' => ['content-type' => 'application/json']]);
    }
}
