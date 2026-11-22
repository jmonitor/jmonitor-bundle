<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

final class ErrorMessage
{
    public const MAX_LENGTH = 500;

    /**
     * The message, followed by its cause when the message does not already say it.
     */
    public static function of(\Throwable $e): string
    {
        $message = $e->getMessage();
        $cause = $e->getPrevious()?->getMessage();

        if ($cause === null || $cause === '' || str_contains($message, $cause)) {
            return $message;
        }

        return sprintf('%s (%s)', $message, $cause);
    }

    /**
     * Drops the user and the password of the URLs in a message, and cuts it to MAX_LENGTH characters.
     */
    public static function clean(string $message): string
    {
        $message = preg_replace('~\b([a-z][a-z0-9+.-]*://)[^\s/?#]*@~i', '$1', $message) ?? $message;

        return mb_strlen($message) > self::MAX_LENGTH ? mb_substr($message, 0, self::MAX_LENGTH - 1) . '…' : $message;
    }
}
