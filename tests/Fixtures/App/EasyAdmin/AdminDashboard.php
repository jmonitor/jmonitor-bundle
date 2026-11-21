<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard as AsAdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

#[AsAdminDashboard(routePath: '/admin', routeName: 'admin')]
final class AdminDashboard extends AbstractDashboardController
{
    public function __construct(
        #[Autowire(param: 'jmonitor.easyadmin.enabled')]
        private readonly bool $jmonitor,
    ) {}

    public function index(): Response
    {
        return $this->render('@EasyAdmin/page/content.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield from parent::configureMenuItems();

        if ($this->jmonitor) {
            yield MenuItem::linkToRoute('JMonitor', 'internal:desktop', 'admin_jmonitor_index');
        }
    }
}
