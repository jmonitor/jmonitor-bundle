<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector;

use Jmonitor\Utils\UrlFetcher;

/**
 * Answers every URL with the same content, or fails like a closed port when there is none.
 */
final class FakeUrlFetcher extends UrlFetcher
{
    public int $calls = 0;
    public ?string $url = null;

    public function __construct(
        public ?string $content,
        private readonly ?\Throwable $failure = null,
    ) {}

    public function fetch(string $url): string
    {
        $this->calls++;
        $this->url = $url;

        if ($this->content === null) {
            throw $this->failure ?? new \RuntimeException(sprintf('Failed to fetch "%s": Connection refused', $url));
        }

        return $this->content;
    }
}
