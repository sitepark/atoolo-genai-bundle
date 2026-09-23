<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test;

use Atoolo\Index\Service\Indexer\IndexerCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Compiles a container with the index-bundle and this bundle, to make sure
 * the GenAI indexer and its document dumper are picked up by the generic
 * services of the index-bundle.
 */
class ContainerTest extends TestCase
{
    /**
     * @var array<string,string[]>
     */
    private array $tagged = [];

    public function setUp(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());

        foreach (
            [
                'atoolo_resource.resource_channel',
                'atoolo_resource.resource_loader',
                'atoolo_resource.navigation_hierarchy_loader',
                'logger',
            ] as $id
        ) {
            $container->setDefinition(
                $id,
                (new Definition(\stdClass::class))->setSynthetic(true),
            );
        }

        $indexConfig = dirname(
            (string) (new \ReflectionClass(IndexerCollection::class))
                ->getFileName(),
        ) . '/../../../config';
        $this->load($container, $indexConfig, 'indexer.yaml');
        $this->load($container, $indexConfig, 'commands.yaml');
        $this->load($container, __DIR__ . '/../config', 'genai.yaml');
        $this->load($container, __DIR__ . '/../config', 'indexer.yaml');
        $this->load($container, __DIR__ . '/../config', 'commands.yaml');

        $tagged = &$this->tagged;
        $container->addCompilerPass(
            new class ($tagged) implements CompilerPassInterface {
                /**
                 * @param array<string,string[]> $tagged
                 */
                public function __construct(private array &$tagged) {}

                public function process(ContainerBuilder $container): void
                {
                    foreach (
                        [
                            'atoolo_index.indexer',
                            'atoolo_index.indexer.document_dumper',
                            'atoolo_genai.indexer.document_enricher',
                            'command',
                            'container.env_var_loader',
                        ] as $tag
                    ) {
                        $this->tagged[$tag] = array_keys(
                            $container->findTaggedServiceIds($tag),
                        );
                    }
                }
            },
            PassConfig::TYPE_BEFORE_REMOVING,
        );

        $container->compile();
    }

    public function testGenAiIndexerIsRegistered(): void
    {
        $this->assertContains(
            'atoolo_genai.indexer.internal_resource_indexer',
            $this->tagged['atoolo_index.indexer'],
            'the GenAI indexer should be part of the indexer collection',
        );
    }

    public function testGenAiDocumentDumperIsRegistered(): void
    {
        $this->assertContains(
            'atoolo_genai.indexer.index_document_dumper',
            $this->tagged['atoolo_index.indexer.document_dumper'],
            'the GenAI dumper should be selectable via --source genai',
        );
    }

    public function testEnricherIsRegistered(): void
    {
        $this->assertContains(
            'Atoolo\GenAi\Service\Indexer\SiteKit\DefaultGenAiDocumentEnricher',
            $this->tagged['atoolo_genai.indexer.document_enricher'],
            'the GenAI enricher should feed the GenAI indexer',
        );
    }

    public function testAskCommandIsRegistered(): void
    {
        $this->assertContains(
            'Atoolo\GenAi\Console\Command\Ask',
            $this->tagged['command'],
            'genai:ask should be available',
        );
    }

    public function testEnvVarLoaderIsRegistered(): void
    {
        $this->assertContains(
            'Atoolo\GenAi\Service\EnvVarLoader',
            $this->tagged['container.env_var_loader'],
            'GENAI_URL should be taken apart into the connection parts',
        );
    }

    public function testConnectionUrlFallsBackToLocalhost(): void
    {
        $this->assertEquals(
            'http://localhost:8080',
            $this->resolveConnectionUrl([]),
            'without any environment the local application should be used',
        );
    }

    public function testConnectionUrlFromHostAndPort(): void
    {
        $this->assertEquals(
            'https://genai.example.com:9090/genai',
            $this->resolveConnectionUrl([
                'GENAI_SCHEME' => 'https',
                'GENAI_HOST' => 'genai.example.com',
                'GENAI_PORT' => '9090',
                'GENAI_PATH' => '/genai',
            ]),
            'every part should be configurable on its own',
        );
    }

    public function testConnectionUrlWithHostOnly(): void
    {
        $this->assertEquals(
            'http://genai.example.com:8080',
            $this->resolveConnectionUrl(['GENAI_HOST' => 'genai.example.com']),
            'a part that is not set should keep its default',
        );
    }

    public function testApiKeyFromTheEnvironment(): void
    {
        $this->assertEquals(
            'secret',
            $this->resolveParameter(
                ['GENAI_API_KEY' => 'secret'],
                'atoolo_genai.connection.api_key',
            ),
            'the api key should be configurable through the environment',
        );
    }

    public function testWithoutAnApiKeyNoneIsConfigured(): void
    {
        $this->assertEquals(
            '',
            $this->resolveParameter([], 'atoolo_genai.connection.api_key'),
            'without a key none is sent, which disables the remote api',
        );
    }

    public function testTimeoutFromTheEnvironment(): void
    {
        $this->assertEquals(
            '60',
            $this->resolveParameter(
                ['GENAI_TIMEOUT' => '60'],
                'atoolo_genai.connection.timeout',
            ),
            'the timeout should be configurable through the environment',
        );
    }

    private function resolveConnectionUrl(array $env): string
    {
        return $this->resolveParameter($env, 'atoolo_genai.connection.url');
    }

    /**
     * Compiles the bundle configuration with the given environment and
     * resolves one of its parameters, so that what reaches the http client
     * is checked the way the application builds it.
     *
     * @param array<string,string> $env
     */
    private function resolveParameter(array $env, string $name): string
    {
        $server = $_SERVER;
        foreach (
            [
                'GENAI_SCHEME',
                'GENAI_HOST',
                'GENAI_PORT',
                'GENAI_PATH',
                'GENAI_API_KEY',
                'GENAI_TIMEOUT',
            ] as $variable
        ) {
            unset($_SERVER[$variable]);
        }
        foreach ($env as $variable => $value) {
            $_SERVER[$variable] = $value;
        }

        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
            $container->setDefinition(
                'atoolo_resource.resource_channel',
                (new Definition(\stdClass::class))->setSynthetic(true),
            );
            $this->load($container, __DIR__ . '/../config', 'genai.yaml');
            $container->compile(true);

            return (string) $container->getParameter($name);
        } finally {
            $_SERVER = $server;
        }
    }

    private function load(
        ContainerBuilder $container,
        string $dir,
        string $file,
    ): void {
        (new YamlFileLoader($container, new FileLocator($dir)))->load($file);
    }
}
