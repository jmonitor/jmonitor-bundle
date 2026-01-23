<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Command\Dto;

class Limits
{
    private ?int $timeLimit;
    private  ?int $memoryLimit;

    public function __construct(int|string|null $timeLimit = null, int|string|null $memoryLimit = null)
    {
        $this->timeLimit = $timeLimit !== null ? (int) $timeLimit : null;
        $this->timeLimit = $this->timeLimit > 0 ? time() + $this->timeLimit : null;

        $this->setMemoryLimit($memoryLimit);
    }

    public function limitReached(): bool
    {
        return $this->isStartLimitReached() || $this->isMemoryLimitReached();
    }

    private function isStartLimitReached(): bool
    {
        if ($this->timeLimit === null) {
            return false;
        }

        return time() > $this->timeLimit;
    }

    private function isMemoryLimitReached(): bool
    {
        if ($this->memoryLimit === null) {
            return false;
        }

        return memory_get_usage() > $this->memoryLimit;
    }

    private function setMemoryLimit(string|int|null $memoryLimit): void
    {
        if (is_int($memoryLimit) || $memoryLimit === null) {
            $this->memoryLimit = $memoryLimit;

            return;
        }

        if (is_numeric($memoryLimit)) {
            $this->memoryLimit = (int) $memoryLimit;

            return;
        }

        if (function_exists('ini_parse_quantity')) {
            $this->memoryLimit = ini_parse_quantity($memoryLimit);

            return;
        }

        // TODO en fonction de la version de php
        throw new \InvalidArgumentException('Memory limit is not parsable, consider install php8.2 polyfill to be able to parse it or send a valid integer.');
    }
}
