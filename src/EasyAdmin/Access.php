<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\EasyAdmin;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class Access
{
    public function __construct(
        private readonly bool $required,
        private readonly ?string $permission,
        private readonly ?AuthorizationCheckerInterface $checker,
    ) {}

    public function assertGranted(): void
    {
        if (!$this->required) {
            return;
        }

        if ($this->permission === null || $this->permission === '') {
            $this->deny('JMonitor needs a permission, and the configured one is empty.');
        }

        if ($this->checker === null || !$this->checker->isGranted($this->permission)) {
            $this->deny(\sprintf('JMonitor needs the "%s" permission.', $this->permission));
        }
    }

    private function deny(string $message): never
    {
        $denied = new AccessDeniedException($message);
        $denied->setAttributes([$this->permission]);

        throw new AccessDeniedHttpException($message, $denied);
    }
}
