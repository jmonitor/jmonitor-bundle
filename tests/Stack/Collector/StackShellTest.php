<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\StackShell;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StackShellTest extends TestCase
{
    public function testACommandRunsThroughAShellWithinThreeSeconds(): void
    {
        $runner = (new FakeProcessRunner())->answer(['sh', '-c', 'nginx -V 2>&1'], "nginx version: nginx/1.30.7\n");

        self::assertSame("nginx version: nginx/1.30.7\n", (new StackShell($runner))->execute('nginx -V 2>&1'));
        self::assertSame(
            [['command' => ['sh', '-c', 'nginx -V 2>&1'], 'working_directory' => '/', 'timeout' => 3, 'env' => []]],
            $runner->calls,
        );
    }

    public function testACommandThatIsNotFoundIsAMissingPrerequisite(): void
    {
        $runner = (new FakeProcessRunner())->answer(['sh', '-c', 'nginx -V 2>&1'], 'sh: nginx: not found', 127);

        $this->expectException(MissingPrerequisiteException::class);

        (new StackShell($runner))->execute('nginx -V 2>&1');
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function failedOrSilent(): iterable
    {
        yield 'a failure that printed something' => ['nginx: [alert] could not open error log file', 1];
        yield 'a failure that printed nothing' => ['', 1];
        yield 'a success that printed nothing' => ['', 0];
        yield 'no exit code' => ['partial', null];
    }

    #[DataProvider('failedOrSilent')]
    public function testAFailedOrSilentCommandGivesNull(string $output, ?int $exitCode): void
    {
        $runner = (new FakeProcessRunner())->answer(['sh', '-c', 'nginx -T'], $output, $exitCode);

        self::assertNull((new StackShell($runner))->execute('nginx -T'));
    }

    public function testACommandThatRunsTooLongIsNotHidden(): void
    {
        $runner = (new FakeProcessRunner())->fail(['sh', '-c', 'nginx -T'], new \RuntimeException('exceeded the timeout of 3 seconds'));

        $this->expectException(\RuntimeException::class);

        (new StackShell($runner))->execute('nginx -T');
    }
}
