<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Controller;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\DashboardAccess;
use Jmonitor\JmonitorBundle\Dashboard\Page;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class DashboardController
{
    public function __construct(
        private readonly DashboardAccess $access,
        private readonly ?ScreenRegistry $screens,
        private readonly ?ScreenBuilder $builder,
        private readonly ?Flusher $flusher,
        private readonly ?Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        return $this->render('@Jmonitor/dashboard/screen.html.twig', $request, null);
    }

    public function fragment(Request $request): Response
    {
        return $this->render('@Jmonitor/dashboard/_screen.html.twig', $request, null);
    }

    public function screen(Request $request, string $screen): Response
    {
        return $this->render('@Jmonitor/dashboard/screen.html.twig', $request, $screen);
    }

    public function screenFragment(Request $request, string $screen): Response
    {
        return $this->render('@Jmonitor/dashboard/_screen.html.twig', $request, $screen);
    }

    private function render(string $template, Request $request, ?string $id): Response
    {
        $this->access->assertGranted();

        if ($this->screens === null || $this->builder === null || $this->twig === null) {
            throw new NotFoundHttpException();
        }

        $screen = $id === null ? $this->screens->default() : $this->screens->get($id);
        if ($screen === null) {
            throw new NotFoundHttpException();
        }

        $response = new Response($this->twig->render($template, [
            'page' => $this->page($request, $screen, $this->builder),
            'screens' => $this->screens->available(),
            'periods' => Period::cases(),
        ]));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    private function page(Request $request, ScreenInterface $screen, ScreenBuilder $builder): Page
    {
        $storageFailed = false;

        if ($this->flusher !== null) {
            $this->flusher->flush();
            $storageFailed = $this->flusher->lastFailure() !== null;
        }

        return $builder->create(
            $screen,
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
