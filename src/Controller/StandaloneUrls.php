<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Controller;

use Jmonitor\JmonitorBundle\Embed\HostUrlsInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StandaloneUrls implements HostUrlsInterface
{
    public function __construct(private readonly ?UrlGeneratorInterface $router) {}

    public function page(string $path, array $query = []): string
    {
        return $path === ''
            ? $this->router()->generate('jmonitor_dashboard', $query)
            : $this->router()->generate('jmonitor_screen', ['screen' => $path] + $query);
    }

    public function fragment(string $path, array $query = []): string
    {
        return $path === ''
            ? $this->router()->generate('jmonitor_dashboard_fragment', $query)
            : $this->router()->generate('jmonitor_screen_fragment', ['screen' => $path] + $query);
    }

    public function action(string $path): string
    {
        return $this->router()->generate('jmonitor_action', ['screen' => $path]);
    }

    private function router(): UrlGeneratorInterface
    {
        return $this->router ?? throw new NotFoundHttpException();
    }
}
