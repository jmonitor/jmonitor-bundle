<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Embed;

final class PageContent
{
    /**
     * @param list<string>          $stylesheets
     * @param list<string>          $scripts
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $html,
        public readonly string $title,
        public readonly array $stylesheets,
        public readonly array $scripts,
        public readonly array $headers,
    ) {}
}
