<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection;

use Jmonitor\JmonitorBundle\Dashboard\Section\HttpSection;
use Jmonitor\JmonitorBundle\Recording\HttpClient\CallRecorder;
use Jmonitor\JmonitorBundle\Recording\HttpClient\RecordingHttpClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class HttpClientPass implements CompilerPassInterface
{
    /** The lowest priority wraps outermost: a call retried by the application is measured once, retries included. */
    private const DECORATION_PRIORITY = -1024;

    public function process(ContainerBuilder $container): void
    {
        $clients = array_keys($container->findTaggedServiceIds('http_client.client'));

        if ($clients === []) {
            $container->removeDefinition(HttpSection::class);
            $container->removeDefinition(CallRecorder::class);

            return;
        }

        if (!$container->hasDefinition(CallRecorder::class)) {
            return;
        }

        foreach ($clients as $id) {
            $container->register('.jmonitor.recording.' . $id, RecordingHttpClient::class)
                ->setDecoratedService($id, null, self::DECORATION_PRIORITY)
                ->setArguments([new Reference('.inner'), new Reference(CallRecorder::class)]);
        }
    }
}
