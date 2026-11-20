<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Recording\TaskLabel;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;

final class TaskLabelTest extends TestCase
{
    public function testACommandIsLabelledByItsInput(): void
    {
        static::assertSame('app:work --force', TaskLabel::of(new RunCommandMessage('app:work --force')));
    }

    public function testARedispatchedMessageIsLabelledByTheMessageItCarries(): void
    {
        static::assertSame(Ping::class, TaskLabel::of(new RedispatchMessage(new Ping(), 'async')));
        static::assertSame(Ping::class, TaskLabel::of(new RedispatchMessage(new Envelope(new Ping()), 'async')));
        static::assertSame('app:work', TaskLabel::of(new RedispatchMessage(new RunCommandMessage('app:work'), 'async')));
    }

    public function testAnyOtherMessageIsLabelledByItsClass(): void
    {
        static::assertSame(Ping::class, TaskLabel::of(new Ping()));
    }

    public function testAnAnonymousMessageIsLabelledUnderOneName(): void
    {
        static::assertSame('class@anonymous', TaskLabel::of(new class {}));
    }
}
