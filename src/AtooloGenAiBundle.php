<?php

declare(strict_types=1);

namespace Atoolo\GenAi;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\GlobFileLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @codeCoverageIgnore
 */
class AtooloGenAiBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $configDir = __DIR__ . '/../config';

        $container->setParameter('atoolo_genai.src_dir', __DIR__);
        $container->setParameter('atoolo_genai.config_dir', $configDir);

        $locator = new FileLocator($configDir);
        $loader = new GlobFileLoader($locator);
        $loader->setResolver(
            new LoaderResolver(
                [
                    new YamlFileLoader($container, $locator),
                ],
            ),
        );

        $loader->load('genai.yaml');
        $loader->load('indexer.yaml');
        $loader->load('commands.yaml');

        // the assistant is offered through GraphQL only where the
        // application runs the overblog bundle
        if ($container->hasExtension('overblog_graphql')) {
            $loader->load('graphql.yaml');
        }
    }
}
