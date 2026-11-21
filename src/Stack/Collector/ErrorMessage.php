<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

final class ErrorMessage
{
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
     * Drops the user and the password of the URLs in a message.
     */
    public static function clean(string $message): string
    {
        return preg_replace('~\b([a-z][a-z0-9+.-]*://)[^\s/?#]*@~i', '$1', $message) ?? $message;
    }
}
