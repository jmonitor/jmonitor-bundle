<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
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

    public function testTheEnvironmentGivenReachesTheCommand(): void
    {
        $code = 'echo getenv("JMONITOR_PROBE"), ",", getenv("SHELL_VERBOSITY");';
        $runner = new ProcessRunner();

        self::assertSame('1,0', $runner->run([\PHP_BINARY, '-r', $code], __DIR__, 5, ['JMONITOR_PROBE' => '1'])['output']);
        self::assertSame('1,0', $runner->runUntil([\PHP_BINARY, '-r', $code], __DIR__, 5, ['JMONITOR_PROBE' => '1'])['output']);
    }

    public function testTheProxyOfAWebRequestNeverReachesTheCommand(): void
    {
        $code = 'echo getenv("HTTP_PROXY");';
        $runner = new ProcessRunner();
        $previous = getenv('HTTP_PROXY');
        $server = $_SERVER['HTTP_PROXY'] ?? null;
        putenv('HTTP_PROXY=http://evil.example');
        $_SERVER['HTTP_PROXY'] = 'http://evil.example';

        try {
            self::assertSame('', $runner->run([\PHP_BINARY, '-r', $code], __DIR__, 5)['output']);
            self::assertSame('', $runner->runUntil([\PHP_BINARY, '-r', $code], __DIR__, 5)['output']);
            self::assertSame('', $runner->run([\PHP_BINARY, '-r', $code], __DIR__, 5, ['HTTP_PROXY' => 'x'])['output']);
            self::assertSame('', $runner->runUntil([\PHP_BINARY, '-r', $code], __DIR__, 5, ['HTTP_PROXY' => 'x'])['output']);
        } finally {
            putenv($previous === false ? 'HTTP_PROXY' : 'HTTP_PROXY=' . $previous);

            if ($server === null) {
                unset($_SERVER['HTTP_PROXY']);
            } else {
                $_SERVER['HTTP_PROXY'] = $server;
            }
        }
    }

    public function testNoHeaderOfAWebRequestReachesTheCommand(): void
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer s3cret', 'HTTP_COOKIE' => 'PHPSESSID=s3cret'];
        $code = 'echo getenv("HTTP_AUTHORIZATION"), ",", getenv("HTTP_COOKIE"), ",", getenv("JMONITOR_PROBE");';
        $runner = new ProcessRunner();
        $previous = [];
        foreach ($headers as $name => $value) {
            $previous[$name] = [getenv($name), $_SERVER[$name] ?? null];
            putenv($name . '=' . $value);
            $_SERVER[$name] = $value;
        }

        try {
            self::assertSame(',,1', $runner->run([\PHP_BINARY, '-r', $code], __DIR__, 5, ['JMONITOR_PROBE' => '1'])['output']);
            self::assertSame(',,1', $runner->runUntil([\PHP_BINARY, '-r', $code], __DIR__, 5, ['JMONITOR_PROBE' => '1'])['output']);
            self::assertSame(',,', $runner->run([\PHP_BINARY, '-r', $code], __DIR__, 5, $headers)['output']);
        } finally {
            foreach ($previous as $name => [$env, $server]) {
                putenv($env === false ? $name : $name . '=' . $env);

                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    public function testACommandStoppedAtItsTimeLimitGivesWhatItPrinted(): void
    {
        $marker = 'jmonitor-test-' . bin2hex(random_bytes(6));
        $start = microtime(true);

        $run = (new ProcessRunner())->runUntil([\PHP_BINARY, '-r', 'echo "a\n"; flush(); sleep(5);', $marker], __DIR__, 1);

        self::assertLessThan(3, microtime(true) - $start);
        self::assertTrue($run['timed_out']);
        self::assertSame("a\n", $run['output']);
        self::assertSame([], self::processesCarrying($marker), 'the command is stopped');
    }

    public function testACommandWithinItsTimeLimitIsNotTimedOut(): void
    {
        $run = (new ProcessRunner())->runUntil([\PHP_BINARY, '-r', 'echo 1; exit(3);'], __DIR__, 5);

        self::assertSame(['exit_code' => 3, 'output' => '1', 'error_output' => '', 'timed_out' => false], $run);
    }

    public function testACommandThatCannotBeFoundIsAMissingPrerequisiteUntilItsTimeLimitToo(): void
    {
        $this->expectException(MissingPrerequisiteException::class);

        (new ProcessRunner())->runUntil(['jmonitor-no-such-binary'], __DIR__, 5);
    }

    public function testRunStillThrowsOnATimeout(): void
    {
        $this->expectException(ProcessTimedOutException::class);

        (new ProcessRunner())->run([\PHP_BINARY, '-r', 'echo "a\n"; flush(); sleep(5);'], __DIR__, 1);
    }

    /**
     * @return list<string> the process ids whose command line carries $marker
     */
    private static function processesCarrying(string $marker): array
    {
        $found = [];

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            if (str_contains((string) @file_get_contents($file), $marker)) {
                $found[] = basename(\dirname($file));
            }
        }

        return $found;
    }
}
