<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack;

use Jmonitor\JmonitorBundle\Stack\Component;
use PHPUnit\Framework\TestCase;

final class ComponentTest extends TestCase
{
    public function testTheWebServersComeAfterSymfony(): void
    {
        self::assertSame(
            ['system', 'php', 'symfony', 'frankenphp', 'apache', 'nginx'],
            array_map(static fn(Component $component): string => $component->value, Component::cases()),
        );
    }

    public function testLabels(): void
    {
        self::assertSame('Server', Component::System->label());
        self::assertSame('PHP', Component::Php->label());
        self::assertSame('Symfony', Component::Symfony->label());
        self::assertSame('FrankenPHP', Component::FrankenPhp->label());
        self::assertSame('Apache', Component::Apache->label());
        self::assertSame('Nginx', Component::Nginx->label());
    }

    public function testOnlyTheThreeWebServersAreWebServers(): void
    {
        $webServers = array_values(array_filter(Component::cases(), static fn(Component $component): bool => $component->isWebServer()));

        self::assertSame([Component::FrankenPhp, Component::Apache, Component::Nginx], $webServers);
    }

    public function testEveryComponentIsReadOnTheWebServerAnsweringTheRequest(): void
    {
        foreach (Component::cases() as $component) {
            self::assertTrue($component->isNodeLocal(), $component->value);
        }
    }
}
