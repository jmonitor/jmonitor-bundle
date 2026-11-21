<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Embed;

use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class JmonitorPage
{
    public const NOT_RECORDED = RequestRecorder::NOT_RECORDED;

    public function __construct(private readonly ContainerInterface $services) {}

    public function doNotRecord(Request $request): void
    {
        $request->attributes->set(self::NOT_RECORDED, true);
    }

    public function page(Request $request, string $path, HostUrlsInterface $urls, bool $standalone = false): PageContent
    {
        $this->doNotRecord($request);

        return $this->renderer()->page($request, $path, $urls, $standalone);
    }

    public function fragment(Request $request, string $path, HostUrlsInterface $urls): Response
    {
        $this->doNotRecord($request);

        return $this->renderer()->fragment($request, $path, $urls);
    }

    public function action(Request $request, string $path, HostUrlsInterface $urls): Response
    {
        $this->doNotRecord($request);

        return $this->renderer()->action($request, $path, $urls);
    }

    private function renderer(): PageRenderer
    {
        $renderer = $this->services->get(PageRenderer::class);
        \assert($renderer instanceof PageRenderer);

        return $renderer;
    }
}
