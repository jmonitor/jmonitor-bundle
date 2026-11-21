<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Embed\HostUrlsInterface;

final class FakeHostUrls implements HostUrlsInterface
{
    public function __construct(private readonly string $base = '/host') {}

    public function page(string $path, array $query = []): string
    {
        return $this->url('page', $path, $query);
    }

    public function fragment(string $path, array $query = []): string
    {
        return $this->url('fragment', $path, $query);
    }

    public function action(string $path): string
    {
        return $this->url('action', $path, []);
    }

    /** @param array<string, string> $query */
    private function url(string $kind, string $path, array $query): string
    {
        $url = $this->base . '/' . $kind . '/' . $path . '?menu=3';
        foreach ($query as $name => $value) {
            $url .= '&' . rawurlencode($name) . '=' . rawurlencode($value);
        }

        return $url;
    }
}
