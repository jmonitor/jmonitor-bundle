<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\EasyAdmin;

use Jmonitor\JmonitorBundle\EasyAdmin\EasyAdminUrls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class EasyAdminUrlsTest extends TestCase
{
    private UrlGenerator $router;

    protected function setUp(): void
    {
        $routes = new RouteCollection();
        foreach (['admin', 'staff'] as $dashboard) {
            foreach (['index' => '', 'fragment' => '/_fragment', 'action' => '/_action'] as $name => $path) {
                $routes->add($dashboard . '_jmonitor_' . $name, new Route(
                    '/' . $dashboard . '/jmonitor' . $path . '/{path}',
                    ['path' => ''],
                    ['path' => '[a-z][a-z-]*(/[a-z][a-z-]*)*'],
                ));
            }
        }

        $this->router = new UrlGenerator($routes, new RequestContext());
    }

    public function testTheUrlsStayUnderTheRoutesOfTheDashboard(): void
    {
        $urls = new EasyAdminUrls($this->router, 'admin_jmonitor_');

        static::assertSame('/admin/jmonitor', $urls->page(''));
        static::assertSame('/admin/jmonitor/queries?period=6h', $urls->page('queries', ['period' => '6h']));
        static::assertSame('/admin/jmonitor/stack/mysql', $urls->page('stack/mysql'));
        static::assertSame('/admin/jmonitor/_fragment/queries', $urls->fragment('queries'));
        static::assertSame('/admin/jmonitor/_action/probe', $urls->action('probe'));
    }

    public function testTheDashboardIsReadFromTheRouteOfTheRequest(): void
    {
        static::assertSame('/staff/jmonitor', EasyAdminUrls::fromRoute($this->router, 'staff_jmonitor_action')->page(''));
    }

    public function testARouteOutsideJmonitorIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EasyAdminUrls::fromRoute($this->router, 'app_hello');
    }
}
