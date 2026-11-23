<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

final class ErrorMessage
{
    public const MAX_LENGTH = 500;
    public const UNREADABLE = 'The reason cannot be shown.';

    private const SECRET_PARAMETERS = 'password|passwd|pwd|pass|auth|secret|secret_key|access_key|session_token|token|key';

    private const MAX_CLEANED_LENGTH = 8000;

    private const RULES = [
        '~(?<![a-z0-9])(' . self::SECRET_PARAMETERS . ')=[^&;"\'\s]*~iu' => '$1=…',
        '~(["\'])((?:[a-z][a-z0-9+.-]*:)?//)[^"\']*@~iu' => '$1$2',
        '~((?:[a-z][a-z0-9+.-]*:|(?<![\w+.:/-]))//)\S*@~iu' => '$1',
        '~Invalid option\(s\) ".*?" passed to~su' => 'Invalid option(s) passed to',
        '~Unknown option found( in DSN)?: \[.*?\]\.(?=\s+Allowed options are \[|\z)~su' => 'Unknown option found$1.',
        '~\buser (?:\'[^\']*\'(?:@\'[^\']*\')?|"[^"]*")~iu' => 'user …',
    ];

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
     * Drops what a message carries of a secret, the credentials of an address, the value of a password or key
     * parameter, the option names a transport copies, the account a database server names, and cuts it to MAX_LENGTH characters.
     */
    public static function clean(string $message): string
    {
        $message = mb_substr(mb_scrub($message), 0, self::MAX_CLEANED_LENGTH);

        foreach (self::RULES as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message);

            if ($message === null) {
                return self::UNREADABLE;
            }
        }

        return mb_strlen($message) > self::MAX_LENGTH ? mb_substr($message, 0, self::MAX_LENGTH - 1) . '…' : $message;
    }
}
