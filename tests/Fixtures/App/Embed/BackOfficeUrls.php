<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed;

use Jmonitor\JmonitorBundle\Embed\HostUrlsInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BackOfficeUrls implements HostUrlsInterface
{
    private const MENU = ['menu' => '3'];

    public function __construct(private readonly UrlGeneratorInterface $router) {}

    public function page(string $path, array $query = []): string
    {
        return $this->router->generate('back_office_monitoring', ['path' => $path] + self::MENU + $query);
    }

    public function fragment(string $path, array $query = []): string
    {
        return $this->router->generate('back_office_monitoring_fragment', ['path' => $path] + self::MENU + $query);
    }

    public function action(string $path): string
    {
        return $this->router->generate('back_office_monitoring_action', ['path' => $path] + self::MENU);
    }
}
