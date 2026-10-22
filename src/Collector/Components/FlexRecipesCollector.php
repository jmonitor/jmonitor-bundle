<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\Exceptions\CollectorException;
use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class FlexRecipesCollector implements ComponentCollectorInterface
{
    private const RETRY_DELAY = 3600;
    private const NOT_RUNNABLE_EXIT_CODES = [126, 127];

    private CommandRunner $commandRunner;
    private ?string $command;
    private ?int $timeout;

    private array $propertyCache = [];
    private ?int $retryAfter = null;
    private bool $disabled = false;

    public function __construct(CommandRunner $commandRunner, ?string $command = null, ?int $timeout = 5)
    {
        $this->commandRunner = $commandRunner;
        $this->command = $command;
        $this->timeout = $timeout;
    }

    public function boot(): void {}

    /**
     * @return array{up_to_date: true}|array{up_to_date: false, outdated_recipes: array<string>}|array{}
     */
    public function collect(): array
    {
        if ($this->disabled) {
            return [];
        }

        $today = date('Y-m-d');

        if (isset($this->propertyCache[$today])) {
            return $this->propertyCache[$today];
        }

        if ($this->retryAfter !== null && time() < $this->retryAfter) {
            return [];
        }

        $command = $this->command ?? ['composer', 'recipes', '-o'];

        try {
            $run = $this->commandRunner->runProcess($command, $this->timeout);
        } catch (ProcessTimedOutException|ProcessSignaledException $e) {
            throw $this->transientFailure($e->getMessage(), $e);
        } catch (ExceptionInterface $e) {
            throw $this->definitiveFailure($e->getMessage(), $e);
        }

        if ($run['exit_code'] === 0) {
            $result = ['up_to_date' => true];
        } elseif ($run['exit_code'] === 1 && ($outdatedRecipes = $this->parseOutput($run['output'])) !== []) {
            // composer recipes -o exits with 1 both when recipes are outdated and on errors
            $result = ['up_to_date' => false, 'outdated_recipes' => $outdatedRecipes];
        } else {
            $message = sprintf('Flex recipes command exited with code %s: %s', $run['exit_code'] ?? 'null', trim($run['error_output']));

            throw in_array($run['exit_code'], self::NOT_RUNNABLE_EXIT_CODES, true)
                ? $this->definitiveFailure($message)
                : $this->transientFailure($message);
        }

        $this->propertyCache = [$today => $result];

        return $result;
    }

    private function transientFailure(string $message, ?\Throwable $previous = null): CollectorException
    {
        $this->retryAfter = time() + self::RETRY_DELAY;

        return new CollectorException($message, __CLASS__, $previous);
    }

    private function definitiveFailure(string $message, ?\Throwable $previous = null): CollectorException
    {
        $this->disabled = true;

        return new CollectorException($message . ' (flex recipes collection disabled)', __CLASS__, $previous);
    }

    private function parseOutput(string $output): array
    {
        $output = str_replace('Outdated recipes.', '', $output);
        $output = explode('Run:', $output)[0];
        $output = trim($output);

        if (empty($output)) {
            return [];
        }

        $lines = explode("\n", $output);
        $recipes = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '* ')) {
                $recipes[] = trim(substr($line, 2));
            }
        }

        return $recipes;
    }
}
