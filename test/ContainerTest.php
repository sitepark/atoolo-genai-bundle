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

    private function load(
        ContainerBuilder $container,
        string $dir,
        string $file,
    ): void {
        (new YamlFileLoader($container, new FileLocator($dir)))->load($file);
    }
}
