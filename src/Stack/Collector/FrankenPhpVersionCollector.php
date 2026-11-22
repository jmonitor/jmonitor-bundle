<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;

final readonly class FrankenPhpVersionCollector implements CollectorInterface
{
    private const TIMEOUT = 3;

    public function __construct(
        private ProcessRunner $runner,
        private string $binary,
    ) {}

    /**
     * @return array{version: string}
     *
     * @throws MissingPrerequisiteException the binary cannot be run
     * @throws \RuntimeException            the output is not that of "frankenphp version"
     */
    public function collect(): array
    {
        $run = $this->runner->run([$this->binary, 'version'], '/', self::TIMEOUT);

        if (preg_match('/\bFrankenPHP\s+v?(\d+\.\d+\.\d+)\b/i', $run['output'], $matches) !== 1) {
            throw new \RuntimeException(sprintf('The output of "%s version" is not recognised.', $this->binary));
        }

        return ['version' => $matches[1]];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'frankenphp.version';
    }
}
