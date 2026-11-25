<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Dashboard\RecordedScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Tests\Dashboard\StubSection;
use PHPUnit\Framework\TestCase;

final class ComposedScreenTest extends TestCase
{
    public function testItSaysWhatItWasGiven(): void
    {
        $screen = new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, []);

        static::assertSame(['background', 'Background', 'Work done in the background', Space::Background], [$screen->id(), $screen->label(), $screen->heading(), $screen->space()]);
        static::assertInstanceOf(RecordedScreenInterface::class, $screen);
        static::assertNotInstanceOf(StorageScreenInterface::class, $screen);
    }

    public function testItKeepsTheAvailableSectionsInTheirOrder(): void
    {
        $screen = new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, (static function () {
            yield new StubSection('messages');
            yield new StubSection('tasks', available: false);
            yield new StubSection('commands');
        })());

        static::assertSame(['messages', 'commands'], array_map(static fn(SectionInterface $section): string => $section->id(), $screen->sections()));
        static::assertSame(['messages', 'commands'], array_map(static fn(SectionInterface $section): string => $section->id(), $screen->sections()));
        static::assertTrue($screen->isAvailable());
    }

    public function testItIsUnavailableWithoutAnAvailableSection(): void
    {
        static::assertFalse((new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, [new StubSection('tasks', available: false)]))->isAvailable());
        static::assertFalse((new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, []))->isAvailable());
    }
}
