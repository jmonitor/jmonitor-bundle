<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\EasyAdmin;

use Jmonitor\JmonitorBundle\Embed\HostUrlsInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EasyAdminUrls implements HostUrlsInterface
{
    private const MARKER = '_jmonitor_';

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly string $prefix,
    ) {}

    public static function fromRoute(UrlGeneratorInterface $router, string $route): self
    {
        $position = strrpos($route, self::MARKER);

        if ($position === false) {
            throw new \InvalidArgumentException(\sprintf('The "%s" route is not a JMonitor route of EasyAdmin.', $route));
        }

        return new self($router, substr($route, 0, $position + \strlen(self::MARKER)));
    }

    public function page(string $path, array $query = []): string
    {
        return $this->generate('index', $path, $query);
    }

    public function fragment(string $path, array $query = []): string
    {
        return $this->generate('fragment', $path, $query);
    }

    public function action(string $path): string
    {
        return $this->generate('action', $path, []);
    }

    /**
     * @param array<string, string> $query
     */
    private function generate(string $route, string $path, array $query): string
    {
        unset($query['path']);

        return $this->router->generate($this->prefix . $route, ($path !== '' ? ['path' => $path] : []) + $query);
    }
}
