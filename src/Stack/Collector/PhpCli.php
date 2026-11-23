<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Symfony\Component\Process\PhpExecutableFinder;

final readonly class PhpCli
{
    /** @var \Closure(): (string|false) */
    private \Closure $find;

    /**
     * @param ?\Closure(): (string|false) $find the PHP of the command line, PhpExecutableFinder by default
     */
    public function __construct(?\Closure $find = null)
    {
        $this->find = $find ?? static fn(): string|false => (new PhpExecutableFinder())->find(false);
    }

    /**
     * @return list<string>
     *
     * @throws MissingPrerequisiteException none was found
     */
    public function command(): array
    {
        $php = ($this->find)();

        if (!is_string($php) || $php === '') {
            throw new MissingPrerequisiteException('No PHP binary of the command line was found.');
        }

        return [$php];
    }
}
