<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
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
        private readonly ActionGuard $guard,
        private readonly ?LoggerInterface $logger,
    ) {}

    public function page(Request $request, string $path, HostUrlsInterface $urls, bool $standalone = false): PageContent
    {
        $twig = $this->twig();
        [$root, $screen, $path] = $this->resolve($path);
        $page = $this->build($request, $screen, self::query($request, 'rest') === '1');
        $actions = $this->actions($screen, $path) ?? [];
        $token = null;

        if ($actions !== []) {
            try {
                $token = $this->guard->token($request);
            } catch (\Throwable $e) {
                $this->logger?->error(sprintf('JMonitor could not protect the actions of the "%s" screen: %s', $path, $e->getMessage()), ['exception' => $e]);
                $actions = [];
            }
        }

        $spaces = $this->spaces();

        return new PageContent(
            $twig->render('@Jmonitor/dashboard/page.html.twig', [
                'page' => $this->withTabs($page, $root, $spaces),
                'path' => $path,
                'spaces' => $this->entries($spaces, $root),
                'badges' => $this->slots($spaces),
                'periods' => Period::cases(),
                'urls' => $urls,
                'standalone' => $standalone,
                'actions' => $actions,
                'token' => $token,
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
        [$root, $screen, $path] = $this->resolve($path);
        $page = $this->build($request, $screen, true);
        $spaces = $this->spaces();

        return new Response($twig->render('@Jmonitor/dashboard/_screen.html.twig', [
            'page' => $this->withTabs($page, $root, $spaces),
            'urls' => $urls,
            'path' => $path,
            'badges' => $this->slots($spaces),
        ]), Response::HTTP_OK, self::HEADERS);
    }

    public function action(Request $request, string $path, HostUrlsInterface $urls): Response
    {
        if (!$request->isMethod('POST')) {
            throw new MethodNotAllowedHttpException(['POST']);
        }

        [, $screen, $path] = $this->resolve($path);
        $action = $request->request->all()['action'] ?? null;

        if (!$screen instanceof ActionScreenInterface || !\is_string($action) || !\array_key_exists($action, $this->actions($screen, $path) ?? [])) {
            throw new NotFoundHttpException();
        }

        try {
            $allowed = $this->guard->allows($request);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('JMonitor could not check the token of an action of the "%s" screen: %s', $path, $e->getMessage()), ['exception' => $e]);
            $allowed = false;
        }

        if (!$allowed) {
            throw new AccessDeniedHttpException();
        }

        try {
            $screen->run($action);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('JMonitor could not run the "%s" action of the "%s" screen: %s', $action, $path, $e->getMessage()), ['exception' => $e]);
        }

        $instance = self::nonEmpty($request->request->all()[ScreenRequest::INSTANCE] ?? null);

        return new RedirectResponse($urls->page($path, $instance === null ? [] : [ScreenRequest::INSTANCE => $instance]), Response::HTTP_SEE_OTHER, self::HEADERS);
    }

    private function twig(): Environment
    {
        return $this->twig ?? throw new NotFoundHttpException();
    }

    /** @return array{ScreenInterface, ScreenInterface, string} the screen of the first segment, the screen shown, its full path */
    private function resolve(string $path): array
    {
        if ($path === '') {
            $screen = $this->screens->default($this->builder->storageUsable());

            return [$screen, $screen, $screen->id()];
        }

        $segments = explode('/', $path);

        if (\count($segments) > 2 || preg_grep('/\A[a-z][a-z-]*\z/', $segments, \PREG_GREP_INVERT) !== []) {
            throw new NotFoundHttpException();
        }

        $root = $this->screens->get($segments[0]) ?? throw new NotFoundHttpException();

        if (!isset($segments[1])) {
            return [$root, $root, $path];
        }

        if (!$root instanceof ParentScreenInterface) {
            throw new NotFoundHttpException();
        }

        try {
            $child = $root->child($segments[1]);
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('JMonitor could not open the "%s" screen: %s', $path, $e->getMessage()), ['exception' => $e]);

            throw new NotFoundHttpException();
        }

        return [$root, $child ?? throw new NotFoundHttpException(), $path];
    }

    /** @return array<string, string>|null null when the screen could not list them */
    private function actions(ScreenInterface $screen, string $path): ?array
    {
        if (!$screen instanceof ActionScreenInterface) {
            return [];
        }

        try {
            return $screen->actions();
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('JMonitor could not list the actions of the "%s" screen: %s', $path, $e->getMessage()), ['exception' => $e]);

            return null;
        }
    }

    /** @return array<string, non-empty-list<ScreenInterface>> by the value of the space */
    private function spaces(): array
    {
        $spaces = [];

        foreach ($this->screens->spaces() as $space) {
            $screens = $this->screens->in($space);

            if ($screens !== []) {
                $spaces[$space->value] = $screens;
            }
        }

        return $spaces;
    }

    /**
     * @param array<string, non-empty-list<ScreenInterface>> $spaces
     *
     * @return list<array{id: string, label: string, path: string, current: bool}>
     */
    private function entries(array $spaces, ScreenInterface $root): array
    {
        $entries = [];

        foreach ($spaces as $value => $screens) {
            $space = Space::from($value);
            $entries[] = ['id' => $value, 'label' => $space->label(), 'path' => $screens[0]->id(), 'current' => $space === $root->space()];
        }

        return $entries;
    }

    /**
     * @param array<string, non-empty-list<ScreenInterface>> $spaces
     *
     * @return array<string, ?NavBadge> the badge of the only screen of a space, by the value of the space
     */
    private function slots(array $spaces): array
    {
        $badges = [];

        foreach ($spaces as $space => $screens) {
            if (\count($screens) === 1 && $screens[0] instanceof NavBadgeScreenInterface) {
                $badges[$space] = $this->badge($screens[0]);
            }
        }

        return $badges;
    }

    /** @param array<string, non-empty-list<ScreenInterface>> $spaces */
    private function withTabs(Page $page, ScreenInterface $root, array $spaces): Page
    {
        $screens = $spaces[$root->space()->value] ?? [];

        if (\count($screens) < 2) {
            return $page;
        }

        $query = $page->period === null ? [] : ['period' => $page->period->label()];

        return $page->withTabs(new SubNav(array_map(fn(ScreenInterface $screen): SubNavItem => new SubNavItem(
            $screen->label(),
            $screen->id(),
            $screen->id() === $root->id(),
            null,
            $query,
            $screen instanceof NavBadgeScreenInterface ? $this->badge($screen) : null,
        ), $screens), null, $root->space()->label()));
    }

    private function badge(NavBadgeScreenInterface $screen): ?NavBadge
    {
        try {
            return $screen->navBadge();
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('JMonitor could not read the navigation badge of the "%s" screen: %s', $screen->id(), $e->getMessage()), ['exception' => $e]);

            return null;
        }
    }

    private function build(Request $request, ScreenInterface $screen, bool $fragment): Page
    {
        $storageFailed = false;

        if ($screen instanceof RecordedScreenInterface && $this->flusher !== null) {
            $this->flusher->flush();
            $storageFailed = $this->flusher->lastFailure() !== null;
        }

        return $this->builder->create(
            $screen,
            self::query($request, 'period'),
            self::query($request, 'sort'),
            self::query($request, 'dir'),
            $storageFailed,
            $fragment,
            self::nonEmpty(self::query($request, ScreenRequest::INSTANCE)),
        );
    }

    private static function query(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;

        return \is_string($value) ? $value : null;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
