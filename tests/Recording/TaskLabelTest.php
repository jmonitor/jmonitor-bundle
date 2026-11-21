<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Recording\TaskLabel;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

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

    public function testAServiceCallIsLabelledByTheServiceAndMethodItCalls(): void
    {
        static::assertSame('@app.cleaner', TaskLabel::of(new ServiceCallMessage('app.cleaner')));
        static::assertSame('@app.cleaner::purge', TaskLabel::of(new ServiceCallMessage('app.cleaner', 'purge', ['days' => 7])));
        static::assertSame('@app.cleaner::purge', TaskLabel::of(new RedispatchMessage(new ServiceCallMessage('app.cleaner', 'purge'), 'async')));
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
