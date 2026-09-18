<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\GenAi\Service\Indexer\SiteKit\DefaultGenAiDocumentEnricher;
use Atoolo\Index\Service\Indexer\ContentCollector;
use Atoolo\Index\Service\Indexer\IndexDocumentDumper;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\Loader\SiteKitNavigationHierarchyLoader;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceLoader;
use PHPUnit\Framework\TestCase;

/**
 * Runs the generic dumper of the index-bundle with the GenAI factory and the
 * GenAI enricher, which is exactly what `index:dump-document --source genai`
 * does.
 */
class GenAiDumpTest extends TestCase
{
    public function testDumpProducesSnakeCaseJson(): void
    {
        $dumper = new IndexDocumentDumper(
            $this->createResourceLoader(),
            [$this->createEnricher()],
            new GenAiDocumentFactory(),
            'genai',
        );

        $dump = $dumper->dump(['/a/b.php']);

        $this->assertCount(1, $dump, 'one document expected');
        $fields = $dump[0];

        $this->assertEquals('123', $fields['id'], 'unexpected id');
        $this->assertEquals('genai', $fields['source'], 'unexpected source');
        $this->assertEquals(
            'A title',
            $fields['title'],
            'unexpected title',
        );
        $this->assertEquals(
            'de',
            $fields['language'],
            'unexpected language',
        );
        $this->assertEquals(
            'de_DE',
            $fields['locale'],
            'the full locale should be part of the document',
        );
        $this->assertStringStartsWith(
            'sha256:',
            $fields['content_hash'],
            'the dump should carry the content hash',
        );

        foreach (array_keys($fields) as $key) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                (string) $key,
                'all json keys should be snake_case',
            );
        }

        $json = json_encode($fields, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(
            '"object_type":"content"',
            $json,
            'the dump should be encodable as json',
        );
    }

    public function testDumpGetsSource(): void
    {
        $dumper = new IndexDocumentDumper(
            $this->createResourceLoader(),
            [],
            new GenAiDocumentFactory(),
            'genai',
        );

        $this->assertEquals(
            'genai',
            $dumper->getSource(),
            'unexpected source',
        );
    }

    private function createEnricher(): DefaultGenAiDocumentEnricher
    {
        $navigationLoader = $this->createStub(
            SiteKitNavigationHierarchyLoader::class,
        );
        $navigationLoader->method('loadRoot')->willReturn(
            new Resource(
                '',
                'root',
                '',
                '',
                ResourceLanguage::default(),
                new DataBag(['siteGroup' => ['id' => 999]]),
            ),
        );

        $contentCollector = $this->createStub(ContentCollector::class);
        $contentCollector->method('collect')->willReturn('collected content');

        return new DefaultGenAiDocumentEnricher(
            $navigationLoader,
            $contentCollector,
            'genai',
        );
    }

    private function createResourceLoader(): ResourceLoader
    {
        $loader = $this->createStub(ResourceLoader::class);
        $loader->method('load')->willReturn(new Resource(
            '/a/b.php',
            '123',
            'b',
            'content',
            ResourceLanguage::default(),
            new DataBag([
                'locale' => 'de_DE',
                'url' => '/a/b.php',
                'base' => ['title' => 'A title'],
                'metadata' => ['description' => 'A description'],
            ]),
        ));
        return $loader;
    }
}
