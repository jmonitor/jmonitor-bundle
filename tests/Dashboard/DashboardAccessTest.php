<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\DashboardAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DashboardAccessTest extends TestCase
{
    public function testDisabledIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new DashboardAccess(false, true, false, null))->assertGranted();
    }

    public function testOpenInDebugWithoutPermission(): void
    {
        (new DashboardAccess(true, true, false, null))->assertGranted();

        $this->addToAssertionCount(1);
    }

    public function testNotFoundOutsideDebugWithoutPermission(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new DashboardAccess(true, false, false, null))->assertGranted();
    }

    public function testPermissionIsEnforced(): void
    {
        (new DashboardAccess(true, false, true, 'ROLE_ADMIN', $this->checker(true)))->assertGranted();
        (new DashboardAccess(true, true, true, 'ROLE_ADMIN', $this->checker(true)))->assertGranted();

        $this->expectException(AccessDeniedException::class);

        (new DashboardAccess(true, true, true, 'ROLE_ADMIN', $this->checker(false)))->assertGranted();
    }

    public function testARequiredPermissionThatIsEmptyAtRuntimeRefusesEvenInDebug(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        foreach ([null, ''] as $permission) {
            try {
                (new DashboardAccess(true, true, true, $permission, $checker))->assertGranted();
                static::fail(sprintf('An empty permission (%s) opened the page.', var_export($permission, true)));
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function checker(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn($granted);

        return $checker;
    }
}
