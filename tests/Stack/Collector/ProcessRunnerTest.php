<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProcessRunnerTest extends TestCase
{
    public function testItGivesTheExitCodeAndTheOutput(): void
    {
        $run = (new ProcessRunner())->run([\PHP_BINARY, '-r', 'echo 1;'], __DIR__, 5);

        self::assertSame(['exit_code' => 0, 'output' => '1', 'error_output' => ''], $run);
    }

    public function testAFailureIsAnExitCode(): void
    {
        self::assertSame(3, (new ProcessRunner())->run([\PHP_BINARY, '-r', 'exit(3);'], __DIR__, 5)['exit_code']);
    }

    public function testItSetsItsEnvironment(): void
    {
        $run = (new ProcessRunner())->run([\PHP_BINARY, '-r', 'echo getenv("SHELL_VERBOSITY"), ",", getenv("COMPOSER_NO_INTERACTION");'], __DIR__, 5);

        self::assertSame('0,1', $run['output']);
    }

    public function testACommandThatCannotBeFoundIsAMissingPrerequisite(): void
    {
        $this->expectException(MissingPrerequisiteException::class);

        (new ProcessRunner())->run(['jmonitor-no-such-binary'], __DIR__, 5);
    }

    public function testADisabledProcOpenIsAMissingPrerequisite(): void
    {
        $code = sprintf(<<<'PHP'
            require %s;
            try {
                (new Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner())->run([\PHP_BINARY, '-r', 'echo 1;'], __DIR__, 5);
                echo 'no exception';
            } catch (\Throwable $e) {
                echo $e::class;
            }
            PHP, var_export(\dirname(__DIR__, 3) . '/vendor/autoload.php', true));

        $process = new Process([\PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $code]);
        $process->run();

        self::assertSame(MissingPrerequisiteException::class, $process->getOutput(), $process->getErrorOutput());
    }

    public function testAProcessIsStoppedAtItsTimeout(): void
    {
        $start = microtime(true);

        try {
            (new ProcessRunner())->run([\PHP_BINARY, '-r', 'sleep(5);'], __DIR__, 1);
            self::fail('The process was not stopped.');
        } catch (MissingPrerequisiteException $e) {
            self::fail('A timeout is not a missing prerequisite: ' . $e->getMessage());
        } catch (\RuntimeException) {
            self::assertLessThan(3, microtime(true) - $start);
        }
    }
}
