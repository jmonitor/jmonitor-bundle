<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Controller;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\DashboardAccess;
use Jmonitor\JmonitorBundle\Dashboard\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\RequestsScreenFactory;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class DashboardController
{
    public function __construct(
        private readonly DashboardAccess $access,
        private readonly ?RequestsScreenFactory $screens,
        private readonly ?Flusher $flusher,
        private readonly Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        return $this->render('@Jmonitor/dashboard/requests.html.twig', $request);
    }

    public function fragment(Request $request): Response
    {
        return $this->render('@Jmonitor/dashboard/_requests.html.twig', $request);
    }

    private function render(string $template, Request $request): Response
    {
        $this->access->assertGranted();

        if ($this->screens === null) {
            throw new NotFoundHttpException();
        }

        $response = new Response($this->twig->render($template, [
            'screen' => $this->screen($request, $this->screens),
            'periods' => Period::cases(),
        ]));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    private function screen(Request $request, RequestsScreenFactory $screens): RequestsScreen
    {
        $storageFailed = false;

        if ($this->flusher !== null) {
            $this->flusher->flush();
            $storageFailed = $this->flusher->lastFailure() !== null;
        }

        return $screens->create(
            self::query($request, 'period'),
            self::query($request, 'sort'),
            self::query($request, 'dir'),
            $storageFailed,
        );
    }

    private static function query(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;

        return \is_string($value) ? $value : null;
    }
}
