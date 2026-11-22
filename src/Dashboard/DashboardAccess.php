<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DashboardAccess
{
    public function __construct(
        private readonly bool $enabled,
        private readonly bool $debug,
        private readonly bool $permissionRequired,
        private readonly ?string $permission,
        private readonly ?AuthorizationCheckerInterface $checker = null,
    ) {}

    public function assertGranted(): void
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException();
        }

        if (!$this->permissionRequired) {
            if ($this->debug) {
                return;
            }

            throw new NotFoundHttpException();
        }

        if ($this->permission === null || $this->permission === '') {
            throw new AccessDeniedException('JMonitor needs a permission, and the configured one is empty.');
        }

        if ($this->checker === null || !$this->checker->isGranted($this->permission)) {
            throw new AccessDeniedException();
        }
    }
}
