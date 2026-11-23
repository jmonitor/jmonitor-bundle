<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

enum FailureKind: string
{
    case Unreachable = 'unreachable';
    case MissingPrerequisite = 'prerequisite';
    case Other = 'other';

    /**
     * Reads the whole chain of causes: a missing prerequisite wins over an unreachable server.
     */
    public static function of(\Throwable $e): self
    {
        $kind = self::Other;

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof MissingPrerequisiteException) {
                return self::MissingPrerequisite;
            }

            if ($cause instanceof UnreachableServerException) {
                $kind = self::Unreachable;
            }
        }

        return $kind;
    }
}
