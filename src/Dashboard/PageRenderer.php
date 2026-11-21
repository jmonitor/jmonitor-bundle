<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Embed\HostUrlsInterface;
use Jmonitor\JmonitorBundle\Embed\PageContent;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class PageRenderer
{
    private const HEADERS = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'];

    public function __construct(
        private readonly ScreenRegistry $screens,
        private readonly ScreenBuilder $builder,
        private readonly ?Flusher $flusher,
        private readonly ?Environment $twig,
        private readonly Assets $assets,
        private readonly ActionGuard $guard = new ActionGuard(),
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function page(Request $request, string $path, HostUrlsInterface $urls, bool $standalone = false): PageContent
    {
        $twig = $this->twig();
        $page = $this->build($request, $this->screen($path));
        $actions = $page->screen instanceof ActionScreenInterface ? $page->screen->actions() : [];

        return new PageContent(
            $twig->render('@Jmonitor/dashboard/page.html.twig', [
                'page' => $page,
                'screens' => $this->screens->available(),
                'periods' => Period::cases(),
                'urls' => $urls,
                'standalone' => $standalone,
                'actions' => $actions,
                'token' => $actions === [] ? null : $this->guard->token($request),
            ]),
            'JMonitor — ' . $page->screen->label(),
            $this->assets->stylesheets($request),
            $this->assets->scripts($request),
            self::HEADERS,
        );
    }

    public function fragment(Request $request, string $path, HostUrlsInterface $urls): Response
    {
        $twig = $this->twig();
        $page = $this->build($request, $this->screen($path));

        return new Response($twig->render('@Jmonitor/dashboard/_screen.html.twig', ['page' => $page, 'urls' => $urls]), Response::HTTP_OK, self::HEADERS);
    }

    public function action(Request $request, string $path, HostUrlsInterface $urls): Response
    {
        if (!$request->isMethod('POST')) {
            throw new MethodNotAllowedHttpException(['POST']);
        }

        $screen = $this->screen($path);
        $action = $request->request->all()['action'] ?? null;

        if (!$screen instanceof ActionScreenInterface || !\is_string($action) || !\array_key_exists($action, $screen->actions())) {
            throw new NotFoundHttpException();
        }

        if (!$this->guard->allows($request)) {
            throw new AccessDeniedHttpException();
        }

        try {
            $screen->run($action);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('JMonitor could not run the "%s" action of the "%s" screen: %s', $action, $screen->id(), $e->getMessage()), ['exception' => $e]);
        }

        return new RedirectResponse($urls->page($path), Response::HTTP_SEE_OTHER, self::HEADERS);
    }

    private function twig(): Environment
    {
        return $this->twig ?? throw new NotFoundHttpException();
    }

    private function screen(string $path): ScreenInterface
    {
        if ($path === '') {
            return $this->screens->default($this->builder->storageUsable());
        }

        if (preg_match('/\A[a-z][a-z-]*\z/', $path) !== 1) {
            throw new NotFoundHttpException();
        }

        return $this->screens->get($path) ?? throw new NotFoundHttpException();
    }

    private function build(Request $request, ScreenInterface $screen): Page
    {
        $storageFailed = false;

        if ($screen instanceof StorageScreenInterface && $this->flusher !== null) {
            $this->flusher->flush();
            $storageFailed = $this->flusher->lastFailure() !== null;
        }

        return $this->builder->create(
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
