<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;

/**
 * Counts the messages waiting in each Messenger transport with the jmonitor:stack:queues command, in a process
 * of its own: a transport may hang on its connection, or throw at its construction.
 */
final readonly class QueuesCollector implements CollectorInterface
{
    private const TIMEOUT = 3;
    private const MAX_TRANSPORTS = 50;
    private const MAX_NAME_LENGTH = 200;
    private const TIMED_OUT = 'did not answer in time';
    private const AFTER_TIMED_OUT = 'not counted: an earlier transport did not answer in time';
    private const NO_ANSWER = 'no answer';
    private const UNCOUNTABLE = 'this transport cannot count';
    private const NO_REASON = 'no reason given';

    public function __construct(
        private ProcessRunner $runner,
        private PhpCli $php,
        private string $projectDir,
        private string $environment,
        private bool $debug,
    ) {}

    /**
     * @return array{transports: list<array{name: string, count: ?int, reason: ?string}>} in the order of the command
     *
     * @throws MissingPrerequisiteException the console or the PHP of the command line cannot be found
     * @throws \RuntimeException            the command did not list the transports
     */
    public function collect(): array
    {
        $console = $this->projectDir . '/bin/console';

        if (!is_file($console)) {
            throw new MissingPrerequisiteException(sprintf('The console of the application, %s, was not found.', $console));
        }

        $command = [...$this->php->command(), $console, 'jmonitor:stack:queues', '--env=' . $this->environment];

        if (!$this->debug) {
            $command[] = '--no-debug';
        }

        $run = $this->runner->runUntil($command, $this->projectDir, self::TIMEOUT, ['APP_ENV' => $this->environment, 'APP_DEBUG' => $this->debug ? '1' : '0']);
        [$names, $answers] = self::read($run['output']);

        if ($names === null) {
            throw new \RuntimeException(ErrorMessage::clean($run['timed_out']
                ? 'The command counting the queues did not answer in time.'
                : sprintf('The command counting the queues failed (exit code: %s): %s', $run['exit_code'] ?? 'unknown', trim($run['error_output']))));
        }

        $transports = [];
        $listed = [];
        $silent = $run['timed_out'] ? self::TIMED_OUT : self::NO_ANSWER;

        foreach ($names as $name) {
            $answer = $answers[$name] ?? null;

            if (isset($listed[$name]) || ($answer['skip'] ?? null) === true) {
                continue;
            }

            $listed[$name] = true;
            $transports[] = ['name' => mb_substr($name, 0, self::MAX_NAME_LENGTH), ...self::transport($answer, $silent)];

            if ($answer === null && $silent === self::TIMED_OUT) {
                $silent = self::AFTER_TIMED_OUT;
            }
        }

        return ['transports' => $transports];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'queues';
    }

    /**
     * @return array{?list<string>, array<array-key, array<mixed>>} the names of the first "transports" line, and the first answer of each
     */
    private static function read(string $output): array
    {
        $names = null;
        $answers = [];

        foreach (explode("\n", $output) as $line) {
            $line = json_decode(trim($line), true);

            if (!is_array($line) || array_is_list($line)) {
                continue;
            }

            if ($names === null && is_array($line['transports'] ?? null)) {
                $names = array_slice(array_values(array_filter($line['transports'], is_string(...))), 0, self::MAX_TRANSPORTS);
            } elseif (is_string($line['name'] ?? null) && !isset($answers[$line['name']])) {
                $answers[$line['name']] = $line;
            }
        }

        return [$names, $answers];
    }

    /**
     * @param ?array<mixed> $answer
     * @param string        $silent the reason of a transport without line
     *
     * @return array{count: ?int, reason: ?string}
     */
    private static function transport(?array $answer, string $silent): array
    {
        if ($answer === null) {
            return ['count' => null, 'reason' => $silent];
        }

        if (is_string($answer['error'] ?? null)) {
            return ['count' => null, 'reason' => trim($answer['error']) === '' ? self::NO_REASON : ErrorMessage::clean($answer['error'])];
        }

        $count = $answer['count'] ?? null;

        return is_int($count) && $count >= 0 ? ['count' => $count, 'reason' => null] : ['count' => null, 'reason' => self::UNCOUNTABLE];
    }
}
