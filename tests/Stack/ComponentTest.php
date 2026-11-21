<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack;

use Jmonitor\JmonitorBundle\Stack\Component;
use PHPUnit\Framework\TestCase;

final class ComponentTest extends TestCase
{
    public function testThereAreThreeComponentsInTheOrderOfTheStack(): void
    {
        self::assertSame(
            ['system', 'php', 'symfony'],
            array_map(static fn(Component $component): string => $component->value, Component::cases()),
        );
    }

    public function testLabels(): void
    {
        self::assertSame('Server', Component::System->label());
        self::assertSame('PHP', Component::Php->label());
        self::assertSame('Symfony', Component::Symfony->label());
    }

    public function testEveryComponentIsReadOnTheWebServerAnsweringTheRequest(): void
    {
        foreach (Component::cases() as $component) {
            self::assertTrue($component->isNodeLocal(), $component->value);
        }
    }
}
