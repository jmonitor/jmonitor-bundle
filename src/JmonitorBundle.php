<?php

/*
 * This file is part of the jmonitor/jmonitor-bundle package.
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\JmonitorBundle;

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
use Jmonitor\JmonitorBundle\Collector\SymfonyCollector;
use Jmonitor\JmonitorBundle\Collector\Components\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\Collector\Components\SchedulerCollector;
use Jmonitor\JmonitorBundle\Command\CollectorCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Flex\SymfonyBundle;
use Symfony\Component\Scheduler\Scheduler;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

final class JmonitorBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['project_api_key']) {
            return;
        }

        $container->services()->set(Jmonitor::class)
            ->args([
                $config['project_api_key'],
                $config['http_client'] ? service($config['http_client']) : null,
            ])
        ;

        $collector = $container->services()->set(CollectorCommand::class)
            ->args([
                service(Jmonitor::class),
                $config['logger'] ? service($config['logger']) : null,
            ])
            ->tag('console.command')
        ;

        if ($config['schedule']) {
            $collector->tag('scheduler.task', [
                'frequency' => 15,
                'schedule' => $config['schedule'],
                'trigger' => 'every',
                'arguments' => null, // https://github.com/symfony/symfony/pull/61307
            ]);
        }


        if ($config['collectors']['mysql']['enabled']) {
            $container->services()->set(DoctrineAdapter::class)
                ->args([
                    service('doctrine.dbal.default_connection'),
                ])
            ;

            $container->services()->set(MysqlQueriesCountCollector::class)
                ->args([
                    service(DoctrineAdapter::class),
                    $config['collectors']['mysql']['db_name'],
                ])
                ->tag('jmonitor.collector', ['name' => 'mysql.queries_count'])
            ;

            $container->services()->set(MysqlStatusCollector::class)
                ->args([
                    service(DoctrineAdapter::class),
                ])
                ->tag('jmonitor.collector', ['name' => 'mysql.status'])
            ;

            $container->services()->set(MysqlVariablesCollector::class)
                ->args([
                    service(DoctrineAdapter::class),
                ])
                ->tag('jmonitor.collector', ['name' => 'mysql.variables'])
            ;

            $container->services()->get(Jmonitor::class)
                ->call('addCollector', [service(MysqlQueriesCountCollector::class)])
                ->call('addCollector', [service(MysqlStatusCollector::class)])
                ->call('addCollector', [service(MysqlVariablesCollector::class)])
            ;
        }

        if ($config['collectors']['apache']['enabled']) {
            $container->services()->set(ApacheCollector::class)
                ->args([
                    $config['collectors']['apache']['server_status_url'],
                ])
                ->tag('jmonitor.collector', ['name' => 'apache'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(ApacheCollector::class)]);
        }

        if ($config['collectors']['system']['enabled']) {
            if ($config['collectors']['system']['adapter'] ?? null) {
                $container->services()->set($config['collectors']['system']['adapter']);
            }

            $container->services()->set(SystemCollector::class)
                ->args([
                    $config['collectors']['system']['adapter'] ? service($config['collectors']['system']['adapter']) : null,
                ])
                ->tag('jmonitor.collector', ['name' => 'system'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(SystemCollector::class)]);
        }

        if ($config['collectors']['php']['enabled']) {
            $container->services()->set(PhpCollector::class)
                ->args([
                    $config['collectors']['php']['endpoint'],
                ])
                ->tag('jmonitor.collector', ['name' => 'php'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(PhpCollector::class)]);
        }

        if ($config['collectors']['redis']['enabled']) {
            $container->services()->set(RedisCollector::class)
                ->args([
                    $config['collectors']['redis']['adapter'] ? service($config['collectors']['redis']['adapter']) : $config['collectors']['redis']['dsn'],
                ])
                ->tag('jmonitor.collector', ['name' => 'redis'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(RedisCollector::class)]);
        }

        if ($config['collectors']['caddy']['enabled']) {
            $container->services()->set(CaddyCollector::class)
                ->args([
                    $config['collectors']['caddy']['endpoint'],
                ])
                ->tag('jmonitor.collector', ['name' => 'caddy'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(CaddyCollector::class)]);
        }

        if ($config['collectors']['symfony']['enabled']) {
            $this->loadSymfonyCollector($container, $config['collectors']['symfony']);
        }
    }

    /**
     * https://symfony.com/doc/current/components/config/definition.html
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children() // jmonitor
                ->scalarNode('project_api_key')->defaultNull()->info('You can find it in your jmonitor.io settings. Let empty to disable.')->end()
                ->scalarNode('http_client')->defaultNull()->info('Name of a Psr\Http\Client\ClientInterface service. Optional. If null, Psr18ClientDiscovery will be used.')->end()
                ->scalarNode('logger')->defaultNull()->info('Name of a Psr\Log\LoggerInterface service.')->end()
                ->scalarNode('schedule')->defaultNull()->info('Name of the schedule used to handle the recurring metrics collection. Must be set to enable use of symfony scheduler.')->end()
                ->arrayNode('collectors')
                    ->addDefaultsIfNotSet() // permet de récup un tableau vide si pas de config
                    ->children()
                        ->arrayNode('mysql')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('db_name')->cannotBeEmpty()->info('Db name of your project.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('apache')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('server_status_url')->defaultValue('https://localhost/server-status')->cannotBeEmpty()->info('Url of apache mod_status.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('system')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('adapter')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('redis')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('dsn')->defaultNull()->info('Redis DSN. See https://symfony.com/doc/current/components/cache/adapters/redis_adapter.html')->end()
                                ->scalarNode('adapter')->defaultNull()->info('Redis or Predis service name')->end()
                            ->end()
                        ->end()
                        ->arrayNode('php')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('endpoint')->defaultNull()->info('Url of exposed php metrics endpoint.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('caddy')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('endpoint')->defaultValue('http://localhost:2019/metrics')->cannotBeEmpty()->info('Url of Caddy (or FrankenPHP) metrics endpoint.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('symfony')
                            ->canBeEnabled()
                            ->children()
                                ->arrayNode('flex')
                                    ->canBeEnabled()
                                    ->children()
                                        ->scalarNode('enabled')->defaultValue(class_exists(SymfonyBundle::class))->end()
                                        ->scalarNode('command')->defaultValue('composer recipes -o')->info('Command to collect Flex recipes metrics"')->end()
                                    ->end()
                                ->end()
                                ->booleanNode('scheduler')
                                    ->defaultValue(class_exists(Scheduler::class))
                                    ->info('Collect Symfony Scheduler metrics.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(function ($config): bool {
                    return
                        !empty($config['collectors']['redis']['dsn'])
                        && !empty($config['collectors']['redis']['adapter']);
                })
                ->thenInvalid('You cannot set both "dsn" and "adapter" for Redis collector. Please choose one.')
            ->end()
            ->validate()
            ->ifTrue(function ($config): bool {
                return
                    !empty($config['schedule']['redis']['dsn'])
                    && !class_exists(Scheduler::class);
            })
            ->thenInvalid('You need to install symfony/scheduler to use the "schedule" option.')
            ->end()
        ;
    }

    private function loadSymfonyCollector(ContainerConfigurator $container, array $symfonyConfig): void
    {
        $container->services()->set(CommandRunner::class)
            ->args([
                service('kernel'),
            ]);

        // Symfony collector and its component collectors
        $container->services()->set(SymfonyCollector::class)
            ->args([
                service('kernel'),
                tagged_iterator('jmonitor.symfony.component_collector', 'index'),
            ])
            ->tag('jmonitor.collector', ['name' => 'symfony'])
        ;

        $container->services()->get(Jmonitor::class)->call('addCollector', [service(SymfonyCollector::class)]);

        // Register Symfony component collectors
        if ($symfonyConfig['scheduler']) {
            $container->services()->set(SchedulerCollector::class)
                ->args([
                    service(CommandRunner::class),
                ])
                ->tag('jmonitor.symfony.component_collector', ['index' => 'scheduler'])
            ;
        }

        if ($symfonyConfig['flex']['enabled']) {
            $container->services()->set(FlexRecipesCollector::class)
                ->args([
                    service(CommandRunner::class),
                    $symfonyConfig['flex']['command'],
                ])
                ->tag('jmonitor.symfony.component_collector', ['index' => 'flex'])
            ;
        }
    }
}
