<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector;

/**
 * A Redis client that answers INFO with what it is given, or throws it.
 */
final class FakeRedis
{
    public int $infos = 0;
    public int $configs = 0;
    public int $closes = 0;

    public function __construct(
        private readonly mixed $answer = [],
    ) {}

    public function info(): mixed
    {
        ++$this->infos;

        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(mixed ...$arguments): array
    {
        ++$this->configs;

        return ['save' => '3600 1'];
    }

    public function close(): bool
    {
        ++$this->closes;

        return true;
    }
}
