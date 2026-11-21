<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed;

use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

final class BackOfficeController
{
    public function __construct(
        private readonly JmonitorPage $jmonitor,
        private readonly BackOfficeUrls $urls,
        private readonly ?Environment $twig,
        private readonly ?AuthorizationCheckerInterface $checker,
    ) {}

    public function page(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->denyUnlessAdmin();

        $content = $this->jmonitor->page($request, $path, $this->urls);
        $twig = $this->twig ?? throw new NotFoundHttpException();

        return new Response($twig->render('@BackOffice/back_office.html.twig', ['content' => $content]), Response::HTTP_OK, $content->headers);
    }

    public function fragment(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->denyUnlessAdmin();

        return $this->jmonitor->fragment($request, $path, $this->urls);
    }

    public function action(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->denyUnlessAdmin();

        return $this->jmonitor->action($request, $path, $this->urls);
    }

    private function denyUnlessAdmin(): void
    {
        if ($this->checker !== null && !$this->checker->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException();
        }
    }
}
