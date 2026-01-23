<?php

/*
 * This file is part of the jmonitor/jmonitor-bundle package.
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Collector\Caddy\CaddyCollector;
use Jmonitor\Collector\Mysql\Adapter\DoctrineAdapter;
use Jmonitor\Collector\Mysql\MysqlQueriesCountCollector;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use Jmonitor\Collector\Php\PhpCollector;
use Jmonitor\Collector\Redis\RedisCollector;
use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\Jmonitor;
use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Jmonitor\JmonitorBundle\Collector\Components\MessengerStatsCollector;
use Jmonitor\JmonitorBundle\Collector\SymfonyCollector;
use Jmonitor\JmonitorBundle\Collector\Components\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\Collector\Components\SchedulerCollector;
use Jmonitor\JmonitorBundle\Command\CollectorCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $config = $builder->getParameter('jmonitor.bundle_config');
    $services = $container->services();

    $services->defaults()
        ->autowire(false)
        ->autoconfigure(true)
    ;

    $services->set(Jmonitor::class)
        ->args([
            $config['project_api_key'],
            $config['http_client'] ? service($config['http_client']) : null,
        ])
    ;

    $collector = $services->set(CollectorCommand::class)
        ->args([
            service(Jmonitor::class),
            $config['logger'] ? service($config['logger']) : null,
        ])
        ->tag('console.command')
    ;

    if ($config['collectors']['mysql']['enabled']) {
        $services->set(DoctrineAdapter::class)
            ->args([
                service('doctrine.dbal.default_connection'),
            ])
        ;

        $services->set(MysqlQueriesCountCollector::class)
            ->args([
                service(DoctrineAdapter::class),
                $config['collectors']['mysql']['db_name'],
            ])
            ->tag('jmonitor.collector', ['name' => 'mysql.queries_count'])
        ;

        $services->set(MysqlStatusCollector::class)
            ->args([
                service(DoctrineAdapter::class),
            ])
            ->tag('jmonitor.collector', ['name' => 'mysql.status'])
        ;

        $services->set(MysqlVariablesCollector::class)
            ->args([
                service(DoctrineAdapter::class),
            ])
            ->tag('jmonitor.collector', ['name' => 'mysql.variables'])
        ;

        $services->get(Jmonitor::class)
            ->call('addCollector', [service(MysqlQueriesCountCollector::class)])
            ->call('addCollector', [service(MysqlStatusCollector::class)])
            ->call('addCollector', [service(MysqlVariablesCollector::class)])
        ;
    }

    if ($config['collectors']['apache']['enabled']) {
        $services->set(ApacheCollector::class)
            ->args([
                $config['collectors']['apache']['server_status_url'],
            ])
            ->tag('jmonitor.collector', ['name' => 'apache'])
        ;

        $services->get(Jmonitor::class)->call('addCollector', [service(ApacheCollector::class)]);
    }

    if ($config['collectors']['system']['enabled']) {
        if ($config['collectors']['system']['adapter'] ?? null) {
            $services->set($config['collectors']['system']['adapter']);
        }

        $services->set(SystemCollector::class)
            ->args([
                $config['collectors']['system']['adapter'] ? service($config['collectors']['system']['adapter']) : null,
            ])
            ->tag('jmonitor.collector', ['name' => 'system'])
        ;

        $services->get(Jmonitor::class)->call('addCollector', [service(SystemCollector::class)]);
    }

    if ($config['collectors']['php']['enabled']) {
        $services->set(PhpCollector::class)
            ->args([
                $config['collectors']['php']['endpoint'],
            ])
            ->tag('jmonitor.collector', ['name' => 'php'])
        ;

        $services->get(Jmonitor::class)->call('addCollector', [service(PhpCollector::class)]);
    }

    if ($config['collectors']['redis']['enabled']) {
        $services->set(RedisCollector::class)
            ->args([
                $config['collectors']['redis']['adapter'] ? service($config['collectors']['redis']['adapter']) : $config['collectors']['redis']['dsn'],
            ])
            ->tag('jmonitor.collector', ['name' => 'redis'])
        ;

        $services->get(Jmonitor::class)->call('addCollector', [service(RedisCollector::class)]);
    }

    if ($config['collectors']['caddy']['enabled']) {
        $services->set(CaddyCollector::class)
            ->args([
                $config['collectors']['caddy']['endpoint'],
            ])
            ->tag('jmonitor.collector', ['name' => 'caddy'])
        ;

        $services->get(Jmonitor::class)->call('addCollector', [service(CaddyCollector::class)]);
    }

    if ($config['collectors']['symfony']['enabled']) {
        $symfonyConfig = $config['collectors']['symfony'];

        $services->set(CommandRunner::class)
            ->args([
                service('kernel'),
            ]);

        // Symfony collector and its component collectors
        $services->set(SymfonyCollector::class)
            ->args([
                service('kernel'),
                tagged_iterator('jmonitor.symfony.component_collector', 'index'),
            ])
            ->tag('jmonitor.collector', ['name' => 'symfony'])
        ;

        $services->get(Jmonitor::class)->call('addCollector', [service(SymfonyCollector::class)]);

        // Register Symfony component collectors
        if ($symfonyConfig['scheduler']) {
            $services->set(SchedulerCollector::class)
                ->args([
                    service(CommandRunner::class),
                ])
                ->tag('jmonitor.symfony.component_collector', ['index' => 'scheduler'])
            ;
        }

        if ($symfonyConfig['flex']['enabled']) {
            $services->set(FlexRecipesCollector::class)
                ->args([
                    service(CommandRunner::class),
                    $symfonyConfig['flex']['command'],
                    $symfonyConfig['flex']['cache_ttl'],
                ])
                ->tag('jmonitor.symfony.component_collector', ['index' => 'flex_recipes'])
            ;
        }

        if ($symfonyConfig['messenger']) {
            $services->set(MessengerStatsCollector::class)
                ->args([
                    service(CommandRunner::class),
                ])
                ->tag('jmonitor.symfony.component_collector', ['index' => 'messenger'])
            ;
        }
    }
};
