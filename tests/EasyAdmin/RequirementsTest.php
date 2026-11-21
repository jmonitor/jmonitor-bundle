<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\EasyAdmin;

use Jmonitor\JmonitorBundle\EasyAdmin\Requirements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedVersionProvider(): iterable
    {
        yield 'the minimum' => ['5.10.2'];
        yield 'a later minor' => ['5.11.0'];
        yield 'a later major' => ['6.0.0'];
    }

    #[DataProvider('acceptedVersionProvider')]
    public function testAVersionFromTheMinimumOnIsAccepted(string $installed): void
    {
        Requirements::assertVersion($installed);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedVersionProvider(): iterable
    {
        yield 'a previous patch' => ['5.10.1'];
        yield 'a previous minor' => ['5.2.0'];
        yield 'a previous major' => ['4.29.17'];
    }

    #[DataProvider('refusedVersionProvider')]
    public function testAVersionBelowTheMinimumIsRefused(string $installed): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('The "jmonitor.easyadmin" option requires EasyAdmin 5.10.2 or later, %s is installed.', $installed));

        Requirements::assertVersion($installed);
    }

    public function testTheBundleMustBeAmongTheBundles(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "jmonitor.easyadmin" option requires the EasyAdminBundle: install easycorp/easyadmin-bundle 5.10.2 or later, or turn the option off.');

        Requirements::assertMet([]);
    }

    public function testJmonitorEasyadminBundleIsRefusedFirst(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "jmonitor.easyadmin" option cannot be used together with jmonitor/easyadmin-bundle, which declares the same routes: remove that package, or turn the option off.');

        Requirements::assertMet(['JmonitorEasyAdminBundle' => 'Jmonitor\\EasyAdminBundle\\JmonitorEasyAdminBundle']);
    }
}
