<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack;

use Jmonitor\JmonitorBundle\Stack\Component;
use PHPUnit\Framework\TestCase;

final class ComponentTest extends TestCase
{
    public function testTheWebServersComeAfterSymfony(): void
    {
        self::assertSame(
            ['system', 'php', 'symfony', 'frankenphp', 'apache', 'nginx', 'mysql', 'postgresql'],
            array_map(static fn(Component $component): string => $component->value, Component::cases()),
        );
    }

    public function testMysqlComesAfterTheWebServers(): void
    {
        $cases = Component::cases();

        self::assertSame(Component::Mysql, $cases[array_search(Component::Nginx, $cases, true) + 1]);
        self::assertSame('MySQL', Component::Mysql->label());
        self::assertFalse(Component::Mysql->isWebServer());
    }

    public function testPostgresqlComesAfterMysql(): void
    {
        $cases = Component::cases();

        self::assertSame(Component::Postgresql, $cases[array_search(Component::Mysql, $cases, true) + 1]);
        self::assertSame('postgresql', Component::Postgresql->value);
        self::assertSame('PostgreSQL', Component::Postgresql->label());
        self::assertFalse(Component::Postgresql->isWebServer());
        self::assertFalse(Component::Postgresql->groupsByServer());
    }

    public function testPostgresqlIsReadFromEveryWebServerAlike(): void
    {
        self::assertFalse(Component::Postgresql->isNodeLocal());
    }

    public function testLabels(): void
    {
        self::assertSame('Server', Component::System->label());
        self::assertSame('PHP', Component::Php->label());
        self::assertSame('Symfony', Component::Symfony->label());
        self::assertSame('FrankenPHP', Component::FrankenPhp->label());
        self::assertSame('Apache', Component::Apache->label());
        self::assertSame('Nginx', Component::Nginx->label());
    }

    public function testOnlyTheThreeWebServersAreWebServers(): void
    {
        $webServers = array_values(array_filter(Component::cases(), static fn(Component $component): bool => $component->isWebServer()));

        self::assertSame([Component::FrankenPhp, Component::Apache, Component::Nginx], $webServers);
    }

    public function testOnlyMysqlIsGroupedByServer(): void
    {
        foreach (Component::cases() as $component) {
            self::assertSame($component === Component::Mysql, $component->groupsByServer(), $component->value);
        }
    }

    public function testTheDatabasesAreTheOnlyComponentsNotReadOnTheWebServer(): void
    {
        foreach (Component::cases() as $component) {
            self::assertSame(!\in_array($component, [Component::Mysql, Component::Postgresql], true), $component->isNodeLocal(), $component->value);
        }
    }
}
