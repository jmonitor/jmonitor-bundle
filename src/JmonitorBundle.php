<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle;

use Jmonitor\JmonitorBundle\Collector\Components\CacheableComponentCollectorInterface;
use Jmonitor\JmonitorBundle\DependencyInjection\Compiler\CacheableCollectorPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Flex\SymfonyBundle;
use Symfony\Component\Scheduler\Scheduler;
use Symfony\Component\Messenger\Worker;

final class JmonitorBundle extends AbstractBundle
{
    public function build(ContainerBuilder $builder): void
    {
        parent::build($builder);

        $builder->addCompilerPass(new CacheableCollectorPass());
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['project_api_key']) {
            return;
        }

        $builder->setParameter('jmonitor.bundle_config', $config);

        $container->import('../config/services.php');

        $builder->registerForAutoconfiguration(CacheableComponentCollectorInterface::class)
            ->addTag('jmonitor.cacheable_collector');
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
                                ->scalarNode('server_status_url')->defaultValue('http://localhost/server-status')->cannotBeEmpty()->info('Url of apache mod_status.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('nginx')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('endpoint')->defaultValue('http://localhost/nginx_status')->cannotBeEmpty()->info('Url of nginx stub_status.')->end()
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
                                        ->integerNode('cache_ttl')->defaultValue(3600 * 24)->info('Cache TTL in seconds for Flex recipes metrics.')->end()
                                    ->end()
                                ->end()
                                ->booleanNode('scheduler')
                                    ->defaultValue(class_exists(Scheduler::class))
                                    ->info('Collect Symfony Scheduler metrics.')
                                ->end()
                                ->booleanNode('messenger')
                                    ->defaultValue(class_exists(Worker::class))
                                    ->info('Collect Symfony Messenger metrics.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn($config): bool
                    => !empty($config['collectors']['redis']['dsn'])
                    && !empty($config['collectors']['redis']['adapter']))
                ->thenInvalid('You cannot set both "dsn" and "adapter" for Redis collector. Please choose one.')
            ->end()
        ;
    }
}
