<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Utils\ShellExecutor;

final class StackShell extends ShellExecutor
{
    private const TIMEOUT = 3;

    public function __construct(
        private readonly ProcessRunner $runner,
    ) {}

    /**
     * @return ?string null when the command fails or prints nothing
     *
     * @throws MissingPrerequisiteException the command is not found (126, 127)
     * @throws \RuntimeException            it ran longer than the time limit
     */
    public function execute(string $command): ?string
    {
        try {
            $run = $this->runner->run(['sh', '-c', $command], '/', self::TIMEOUT);
        } catch (MissingPrerequisiteException) {
            throw new MissingPrerequisiteException(sprintf('The command "%s" cannot be run.', $command));
        }

        return $run['exit_code'] === 0 && $run['output'] !== '' ? $run['output'] : null;
    }
}
