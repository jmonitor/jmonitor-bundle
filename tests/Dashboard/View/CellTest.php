<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\View;

use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use PHPUnit\Framework\TestCase;

final class CellTest extends TestCase
{
    public function testARequestEntryPointIsARoute(): void
    {
        $cell = Cell::entryPoint('request', 'GET app_home');

        static::assertSame(['app_home', 'route', ['method' => 'GET']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testACommandEntryPointShowsItsName(): void
    {
        $cell = Cell::entryPoint('command', 'app:work');

        static::assertSame(['app:work', 'route', ['method' => 'CLI']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testAMessageEntryPointShowsItsClass(): void
    {
        $cell = Cell::entryPoint('message', 'App\Message\SendInvoice');

        static::assertSame(['App\Message\SendInvoice', 'route', ['method' => 'MSG']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testATaskEntryPointShowsItsLabel(): void
    {
        $cell = Cell::entryPoint('task', (new TaskKey('default', '28af6db0', 'app:cleanup', 'every 1 hour'))->toString());

        static::assertSame(['app:cleanup', 'route', ['method' => 'TASK']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testATaskEntryPointThatDoesNotParseShowsItsRawKey(): void
    {
        $cell = Cell::entryPoint('task', 'not a task key');

        static::assertSame(['not a task key', 'route', ['method' => 'TASK']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testAnUnknownEntryPointShowsItsRawKeyWithAnEmptyBadge(): void
    {
        $cell = Cell::entryPoint('job', 'GET app_home');

        static::assertSame(['GET app_home', 'route', ['method' => '']], [$cell->text, $cell->kind, $cell->data]);
    }
}
