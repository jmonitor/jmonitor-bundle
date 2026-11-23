<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Command;

use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * Prints one JSON line per transport as soon as it is counted, so that a stop at the timeout keeps the lines
 * already out. The names come first, before any transport is built: building one may connect to its server.
 */
#[AsCommand(name: 'jmonitor:stack:queues', hidden: true)]
final class QueuesCommand extends Command
{
    private const MAX_TRANSPORTS = 50;
    private const SKIPPED = [
        'Symfony\Component\Scheduler\Messenger\SchedulerTransport',
        'Symfony\Component\Messenger\Transport\Sync\SyncTransport',
    ];

    /**
     * @param ContainerInterface $receivers the transports of Messenger, each once under its name
     * @param list<string>       $names
     */
    public function __construct(
        private readonly ContainerInterface $receivers,
        private readonly array $names,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $names = array_slice($this->names, 0, self::MAX_TRANSPORTS);
        self::write($output, ['transports' => $names]);

        foreach ($names as $name) {
            self::write($output, ['name' => $name, ...$this->count($name)]);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{skip: true}|array{count: ?int}|array{error: string}
     */
    private function count(string $name): array
    {
        try {
            $transport = $this->receivers->get($name);

            foreach (self::SKIPPED as $class) {
                if (is_a($transport, $class)) {
                    return ['skip' => true];
                }
            }

            return ['count' => $transport instanceof MessageCountAwareInterface ? $transport->getMessageCount() : null];
        } catch (\Throwable $e) {
            $reason = ErrorMessage::clean(ErrorMessage::of($e));

            return ['error' => trim($reason) === '' ? (new \ReflectionClass($e))->getShortName() : $reason];
        }
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function write(OutputInterface $output, array $line): void
    {
        $output->writeln(
            json_encode($line, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET,
        );
        flush();
    }
}
