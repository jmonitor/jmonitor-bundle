<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Symfony\Component\HttpFoundation\Request;

final class Assets
{
    private const PUBLIC_DIR = __DIR__ . '/../../public/';

    /** @var array<string, string> file => version */
    private array $versions = [];

    /** @return list<string> */
    public function stylesheets(Request $request): array
    {
        return [$this->url($request, 'jmonitor.css')];
    }

    /** @return list<string> */
    public function scripts(Request $request): array
    {
        return [$this->url($request, 'jmonitor.js')];
    }

    private function url(Request $request, string $file): string
    {
        $this->versions[$file] ??= substr((string) hash_file('xxh3', self::PUBLIC_DIR . $file), 0, 8);

        return $request->getBasePath() . '/bundles/jmonitor/' . $file . '?v=' . $this->versions[$file];
    }
}
