<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\Utils\UrlFetcher;

/**
 * Reads the status page of a server, and only that: an answer of the application behind it, a redirection
 * to its login page for one, is not taken for a status page.
 */
final class StatusPageFetcher extends UrlFetcher
{
    private readonly float $seconds;

    /** @var \Closure(string, array<string, mixed>): array{?string, list<string>} */
    private readonly \Closure $open;

    private readonly bool $urlFopen;

    /**
     * @param ?\Closure(string $url, array<string, mixed> $http): array{?string, list<string>} $open
     *        the body, null when nothing answered, and the lines of the response headers
     * @param ?bool $urlFopen null reads the allow_url_fopen setting
     */
    public function __construct(float $timeout = 2.0, ?\Closure $open = null, ?bool $urlFopen = null)
    {
        $this->seconds = $timeout;
        $this->open = $open ?? self::open(...);
        $this->urlFopen = $urlFopen ?? filter_var(ini_get('allow_url_fopen'), \FILTER_VALIDATE_BOOL);
    }

    /**
     * @throws MissingPrerequisiteException allow_url_fopen is off, the address is not http(s), or the answer is not a 200
     * @throws UnreachableServerException   nothing answered in time
     */
    public function fetch(string $url): string
    {
        if (preg_match('~^https?://~i', $url) !== 1) {
            throw new MissingPrerequisiteException(sprintf('%s is not an http(s) address.', $url));
        }

        if (!$this->urlFopen) {
            throw new MissingPrerequisiteException(sprintf('PHP cannot read %s: allow_url_fopen is off.', $url));
        }

        error_clear_last();
        [$body, $headers] = ($this->open)($url, [
            'timeout' => $this->seconds,
            'follow_location' => 0,
            'max_redirects' => 0,
            'ignore_errors' => true,
            'header' => RequestRecorder::PROBE_HEADER . ": 1\r\nConnection: close",
        ]);
        $status = self::status($headers);

        if ($body === null || $status === null) {
            $reason = preg_replace('~^\w+\(.*?\): ~', '', error_get_last()['message'] ?? '');

            throw new UnreachableServerException(sprintf('Nothing answered at %s: %s', $url, $reason !== null && $reason !== '' ? $reason : sprintf('no answer within %g seconds', $this->seconds)));
        }

        if ($status !== 200) {
            throw new MissingPrerequisiteException(sprintf('%s answered HTTP %d: it is not a status page.', $url, $status));
        }

        return $body;
    }

    /**
     * @param list<string> $headers
     *
     * @return ?int the code of the last response: an interim one, a 100 Continue, comes before it
     */
    private static function status(array $headers): ?int
    {
        foreach (array_reverse($headers) as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})\b~', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $http
     *
     * @return array{?string, list<string>}
     */
    private static function open(string $url, array $http): array
    {
        $body = @file_get_contents($url, false, stream_context_create(['http' => $http]));

        if (function_exists('http_get_last_response_headers')) {
            $headers = http_get_last_response_headers();
        } else {
            // @phpstan-ignore nullCoalesce.variable (undefined when nothing answered)
            $headers = $http_response_header ?? null;
        }

        return [$body === false ? null : $body, $headers ?? []];
    }
}
