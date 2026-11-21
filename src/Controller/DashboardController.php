<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Controller;

use Jmonitor\JmonitorBundle\Dashboard\DashboardAccess;
use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class DashboardController
{
    public function __construct(
        private readonly DashboardAccess $access,
        private readonly JmonitorPage $jmonitor,
        private readonly StandaloneUrls $urls,
        private readonly ?Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        return $this->page($request, '');
    }

    public function fragment(Request $request): Response
    {
        return $this->fragmentOf($request, '');
    }

    public function screen(Request $request, string $screen): Response
    {
        return $this->page($request, $screen);
    }

    public function screenFragment(Request $request, string $screen): Response
    {
        return $this->fragmentOf($request, $screen);
    }

    private function page(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->access->assertGranted();

        $content = $this->jmonitor->page($request, $path, $this->urls, true);
        $twig = $this->twig ?? throw new NotFoundHttpException();

        return new Response($twig->render('@Jmonitor/dashboard/layout.html.twig', ['content' => $content]), Response::HTTP_OK, $content->headers);
    }

    private function fragmentOf(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->access->assertGranted();

        return $this->jmonitor->fragment($request, $path, $this->urls);
    }
}
