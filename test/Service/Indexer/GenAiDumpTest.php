<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\GenAi\Service\Indexer\SiteKit\DefaultGenAiDocumentEnricher;
use Atoolo\Index\Service\Indexer\IndexDocumentDumper;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\Loader\SiteKitNavigationHierarchyLoader;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceLoader;
use Atoolo\Resource\ResourceTenant;
use PHPUnit\Framework\TestCase;

/**
 * Runs the generic dumper of the index-bundle with the GenAI factory and the
 * GenAI enricher, which is exactly what `index:dump-document --indexer genai`
 * does.
 */
class GenAiDumpTest extends TestCase
{
    public function testDumpProducesTheDocumentOfTheRemoteApi(): void
    {
        $dumper = new IndexDocumentDumper(
            $this->createResourceLoader(),
            [$this->createEnricher()],
            new GenAiDocumentFactory($this->createResourceChannel()),
            'internal',
            'genai',
        );

        $dump = $dumper->dump(['/a/b.php']);

        $this->assertCount(1, $dump, 'one document expected');
        // the dumper hands back the document; the command json_encodes it
        $data = $dump[0]->jsonSerialize();

        $this->assertEquals('article', $data['type'], 'unexpected type');
        $this->assertEquals('123', $data['id'], 'unexpected id');
        $this->assertEquals('www', $data['channel'], 'unexpected channel');
        $this->assertEquals('internal', $data['source'], 'unexpected source');
        $this->assertEquals('A title', $data['title'], 'unexpected title');
        $this->assertEquals(
            [
                [
                    'type' => 'text',
                    'headline' => 'Öffnungszeiten',
                    'html' => '<p>Montags geschlossen.</p>',
                ],
            ],
            $data['content'],
            'the dump should carry the content sections',
        );

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(
            '"objectType":"content"',
            $json,
            'the json keys are the camelCase keys of the remote api',
        );
    }

    public function testDumpGetsIdAndSource(): void
    {
        $dumper = new IndexDocumentDumper(
            $this->createResourceLoader(),
            [],
            new GenAiDocumentFactory($this->createResourceChannel()),
            'internal',
            'genai',
        );

        $this->assertEquals(
            ['genai', 'internal'],
            [$dumper->getId(), $dumper->getSource()],
            'the dumper should be found by its id and carry the source of '
            . 'the solr indexer',
        );
    }

    private function createEnricher(): DefaultGenAiDocumentEnricher
    {
        $navigationLoader = $this->createStub(
            SiteKitNavigationHierarchyLoader::class,
        );

        return new DefaultGenAiDocumentEnricher(
            $navigationLoader,
            $this->createResourceChannel(),
            'internal',
        );
    }

    private function createResourceChannel(): ResourceChannel
    {
        return new ResourceChannel(
            '',
            'WWW',
            '',
            'www.example.com',
            false,
            '',
            '',
            '',
            '',
            '',
            'www',
            [],
            new DataBag([]),
            $this->createStub(ResourceTenant::class),
        );
    }

    private function createResourceLoader(): ResourceLoader
    {
        $loader = $this->createStub(ResourceLoader::class);
        $loader->method('load')->willReturn(new Resource(
            '/a/b.php',
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
                'content' => [
                    'type' => 'ROOT',
                    'items' => [
                        [
                            'type' => 'main',
                            'items' => [
                                [
                                    'type' => 'text',
                                    'model' => [
                                        'modelType' => 'content.text',
                                        'headline' => 'Öffnungszeiten',
                                        'richText' => [
                                            'modelType' => 'html.richText',
                                            'text' => '<p>Montags '
                                                . 'geschlossen.</p>',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ));
        return $loader;
    }
}
