<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Embed;

interface HostUrlsInterface
{
    /** @param array<string, string> $query */
    public function page(string $path, array $query = []): string;

    /** @param array<string, string> $query */
    public function fragment(string $path, array $query = []): string;

    public function action(string $path): string;
}
