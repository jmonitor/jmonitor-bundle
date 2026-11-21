<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\EasyAdmin;

use Jmonitor\JmonitorBundle\EasyAdmin\Access;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccessTest extends TestCase
{
    public function testWithoutAPermissionEveryoneIsLetIn(): void
    {
        (new Access(false, null, null))->assertGranted();

        $this->addToAssertionCount(1);
    }

    public function testThePermissionLetsInWhoHoldsIt(): void
    {
        (new Access(true, 'ROLE_X', $this->checker(true)))->assertGranted();

        $this->addToAssertionCount(1);
    }

    public function testThePermissionKeepsOutWhoLacksIt(): void
    {
        try {
            (new Access(true, 'ROLE_X', $this->checker(false)))->assertGranted();
            static::fail('Access was granted.');
        } catch (AccessDeniedHttpException $exception) {
            static::assertSame('JMonitor needs the "ROLE_X" permission.', $exception->getMessage());
            static::assertInstanceOf(AccessDeniedException::class, $exception->getPrevious());
        }
    }

    public function testAnEmptyPermissionKeepsEveryoneOut(): void
    {
        $this->assertRefused(new Access(true, '', $this->unusedChecker()), 'JMonitor needs a permission, and the configured one is empty.');
    }

    public function testAPermissionResolvedToNullKeepsEveryoneOut(): void
    {
        $this->assertRefused(new Access(true, null, $this->unusedChecker()), 'JMonitor needs a permission, and the configured one is empty.');
    }

    public function testWithoutAnAuthorizationCheckerThePermissionKeepsEveryoneOut(): void
    {
        $this->assertRefused(new Access(true, 'ROLE_X', null), 'JMonitor needs the "ROLE_X" permission.');
    }

    private function assertRefused(Access $access, string $message): void
    {
        try {
            $access->assertGranted();
            static::fail('Access was granted.');
        } catch (AccessDeniedHttpException $exception) {
            static::assertSame($message, $exception->getMessage());
            static::assertInstanceOf(AccessDeniedException::class, $exception->getPrevious());
        }
    }

    private function checker(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->with('ROLE_X')->willReturn($granted);

        return $checker;
    }

    private function unusedChecker(): AuthorizationCheckerInterface
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        return $checker;
    }
}
