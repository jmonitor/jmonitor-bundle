<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

final class TaskLabel
{
    public static function of(object $message): string
    {
        if ($message instanceof RunCommandMessage) {
            return $message->input;
        }

        if ($message instanceof ServiceCallMessage) {
            return (string) $message;
        }

        if ($message instanceof RedispatchMessage) {
            return self::of($message->envelope instanceof Envelope ? $message->envelope->getMessage() : $message->envelope);
        }

        return ClassName::of($message);
    }
}
