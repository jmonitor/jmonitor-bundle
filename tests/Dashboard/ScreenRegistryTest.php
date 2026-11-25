<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use PHPUnit\Framework\TestCase;

final class ScreenRegistryTest extends TestCase
{
    public function testAvailableScreensKeepTheirOrder(): void
    {
        $registry = new ScreenRegistry([new StubScreen('requests'), new StubScreen('queries'), new StubScreen('cache')]);

        static::assertSame(['requests', 'queries', 'cache'], array_map(static fn(ScreenInterface $screen): string => $screen->id(), $registry->available()));
    }

    public function testAnUnavailableScreenIsHidden(): void
    {
        $registry = new ScreenRegistry([new StubScreen('requests'), new StubScreen('queries', available: false), new StubScreen('cache')]);

        static::assertSame(['requests', 'cache'], array_map(static fn(ScreenInterface $screen): string => $screen->id(), $registry->available()));
        static::assertNull($registry->get('queries'));
        static::assertNull($registry->get('nope'));
        static::assertSame('cache', $registry->get('cache')?->id());
    }

    public function testTheDefaultScreenIsTheFirstAvailableOne(): void
    {
        $registry = new ScreenRegistry((static function () {
            yield new StubScreen('queries', available: false);
            yield new StubScreen('requests');
            yield new StubScreen('cache');
        })());

        static::assertSame('requests', $registry->default()->id());
        static::assertSame('requests', $registry->default()->id());
    }

    public function testWithoutUsableStorageTheDefaultIsTheFirstScreenThatDoesNotReadIt(): void
    {
        $registry = new ScreenRegistry([new StubScreen('requests'), new StubBlockScreen('probe')]);

        static::assertSame('requests', $registry->default(true)->id());
        static::assertSame('probe', $registry->default(false)->id());

        $registry = new ScreenRegistry([new StubScreen('requests'), new StubScreen('queries')]);

        static::assertSame('requests', $registry->default(false)->id());
    }

    public function testSpacesAreThoseWithAnAvailableScreenInTheirOrder(): void
    {
        $registry = new ScreenRegistry([
            new StubBlockScreen('stack', space: Space::Stack),
            new StubScreen('hidden', available: false, space: Space::Background),
            new StubScreen('requests'),
            new StubScreen('queries'),
        ]);

        static::assertSame([Space::Application, Space::Stack], $registry->spaces());
        static::assertSame([], (new ScreenRegistry([new StubScreen('requests', available: false)]))->spaces());
    }

    public function testTheScreensOfASpaceKeepTheOrderOfTheRegistry(): void
    {
        $registry = new ScreenRegistry([
            new StubScreen('requests'),
            new StubBlockScreen('stack', space: Space::Stack),
            new StubScreen('hidden', available: false),
            new StubScreen('queries'),
            new StubScreen('cache'),
        ]);
        $ids = static fn(Space $space): array => array_map(static fn(ScreenInterface $screen): string => $screen->id(), $registry->in($space));

        static::assertSame(['requests', 'queries', 'cache'], $ids(Space::Application));
        static::assertSame(['stack'], $ids(Space::Stack));
        static::assertSame([], $ids(Space::Background));
    }
}
