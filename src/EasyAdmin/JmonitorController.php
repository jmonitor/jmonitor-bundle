<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

#[AdminRoute('/jmonitor', name: 'jmonitor')]
final class JmonitorController
{
    public const PATH = '[a-z][a-z-]*(/[a-z][a-z-]*)*';
    private const ROUTE = ['requirements' => ['path' => self::PATH], 'defaults' => ['path' => '', JmonitorPage::NOT_RECORDED => true]];

    public function __construct(
        private readonly JmonitorPage $jmonitor,
        private readonly ContainerInterface $services,
        private readonly ?string $permission,
    ) {}

    #[AdminRoute(path: '/{path}', name: 'index', options: self::ROUTE + ['methods' => ['GET']])]
    public function page(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->denyUnlessGranted();

        $content = $this->jmonitor->page($request, $path, $this->urls($request));
        $twig = $this->services->get('twig');
        \assert($twig instanceof Environment);

        return new Response($twig->render('@JmonitorEasyAdmin/page.html.twig', ['content' => $content]), Response::HTTP_OK, $content->headers);
    }

    #[AdminRoute(path: '/_fragment/{path}', name: 'fragment', options: self::ROUTE + ['methods' => ['GET']])]
    public function fragment(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->denyUnlessGranted();

        return $this->jmonitor->fragment($request, $path, $this->urls($request));
    }

    #[AdminRoute(path: '/_action/{path}', name: 'action', options: self::ROUTE + ['methods' => ['POST']])]
    public function action(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->denyUnlessGranted();

        return $this->jmonitor->action($request, $path, $this->urls($request));
    }

    private function denyUnlessGranted(): void
    {
        if ($this->permission === null) {
            return;
        }

        $checker = $this->services->has('security.authorization_checker') ? $this->services->get('security.authorization_checker') : null;

        if ($this->permission !== '' && $checker instanceof AuthorizationCheckerInterface && $checker->isGranted($this->permission)) {
            return;
        }

        $message = \sprintf('JMonitor needs the "%s" permission.', $this->permission);
        $denied = new AccessDeniedException($message);
        $denied->setAttributes([$this->permission]);

        throw new AccessDeniedHttpException($message, $denied);
    }

    private function urls(Request $request): EasyAdminUrls
    {
        $router = $this->services->get('router');
        \assert($router instanceof UrlGeneratorInterface);

        return EasyAdminUrls::fromRoute($router, $request->attributes->getString('_route'));
    }
}
