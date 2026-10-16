<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer\Config;

use Atoolo\GenAi\Service\Indexer\Config\GenAiConfigDocuments;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceTenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(GenAiConfigDocuments::class)]
class GenAiConfigDocumentsTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/genai-config-' . uniqid();
        mkdir($this->configDir . '/genai/glossary', 0777, true);
        mkdir($this->configDir . '/genai/knowledge', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->configDir);
    }

    public function testAGlossaryIsReadWithItsEntries(): void
    {
        $this->write('glossary/stadtteile.php', [
            'id' => 40540,
            'title' => 'Stadtteile',
            'definitions' => [
                ['terms' => ['OB'], 'definition' => 'Oberbürgermeister'],
                ['terms' => [' perso ', 'persi', ''], 'definition' => ' Personalausweis '],
                ['terms' => [], 'definition' => 'ohne Begriff'],
                ['terms' => ['ohne Bedeutung'], 'definition' => ''],
            ],
        ]);

        $documents = $this->documents()->documents('p1');

        $this->assertCount(1, $documents, 'one glossary');
        $glossary = $documents[0];
        $this->assertTrue($glossary->isGlossary(), 'a glossary');
        $this->assertEquals('40540', $glossary->id, 'the id of the CMS resource');
        $this->assertEquals('Stadtteile', $glossary->title, 'the title');
        $this->assertEquals('internal', $glossary->source, 'the source of the internal resources');
        $this->assertEquals('p1', $glossary->processId, 'the run');
        $this->assertEquals('www', $glossary->channel, 'the channel of the resource channel');
        $this->assertEquals(
            [
                ['terms' => ['OB'], 'expansion' => 'Oberbürgermeister'],
                ['terms' => ['perso', 'persi'], 'expansion' => 'Personalausweis'],
            ],
            $glossary->entries,
            'the complete entries, stripped',
        );
    }

    public function testAGlossaryWithoutTitleIsNamedAfterItsFile(): void
    {
        $this->write('glossary/stadtteile.php', [
            'id' => 40540,
            'definitions' => [['terms' => ['OB'], 'definition' => 'Oberbürgermeister']],
        ]);

        $this->assertEquals(
            'stadtteile',
            $this->documents()->documents('p1')[0]->title,
            'the file name as title',
        );
    }

    public function testAKnowledgeEntryIsReadWithItsTextAndKeywords(): void
    {
        $this->write('knowledge/bahnhof-21.php', [
            'id' => 40541,
            'headline' => 'Stuttgart 21 – Aktueller Stand',
            'keywords' => ['bahnhof', 'S21'],
            'text' => [
                'normalized' => true,
                'modelType' => 'html.richText',
                'text' => '<p>Stuttgart 21 ist ein Bahnprojekt.</p>',
            ],
        ]);

        $documents = $this->documents()->documents('p1');

        $this->assertCount(1, $documents, 'one knowledge entry');
        $data = $documents[0]->jsonSerialize();
        unset($data['hash']);
        $this->assertEquals(
            [
                'type' => GenAiDocument::TYPE_KNOWLEDGE,
                'id' => '40541',
                'channel' => 'www',
                'source' => 'internal',
                'processId' => 'p1',
                'title' => 'Stuttgart 21 – Aktueller Stand',
                'keywords' => ['bahnhof', 'S21'],
                'text' => '<p>Stuttgart 21 ist ein Bahnprojekt.</p>',
            ],
            $data,
            'the knowledge entry with its text and keywords',
        );
    }

    public function testFilesWithoutIdOrContentAreSkipped(): void
    {
        $this->write('glossary/ohne-id.php', [
            'definitions' => [['terms' => ['OB'], 'definition' => 'Oberbürgermeister']],
        ]);
        $this->write('glossary/leer.php', ['id' => 1, 'definitions' => []]);
        $this->write('knowledge/ohne-text.php', ['id' => 2, 'headline' => 'Leer']);
        file_put_contents($this->configDir . '/genai/knowledge/kaputt.php', '<?php return [');
        file_put_contents($this->configDir . '/genai/knowledge/kein-array.php', '<?php return 1;');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(5))->method('warning');

        $documents = $this->documents($logger)->documents('p1');

        $this->assertCount(0, $documents, 'nothing to send');
    }

    public function testTheTranslationsAreLeftOut(): void
    {
        $this->write('glossary/stadtteile.php', [
            'id' => 40540,
            'definitions' => [['terms' => ['OB'], 'definition' => 'Oberbürgermeister']],
        ]);
        mkdir($this->configDir . '/genai/glossary/stadtteile.translations');
        $this->write('glossary/stadtteile.translations/en_US.php', [
            'id' => 40540,
            'definitions' => [['terms' => ['OB'], 'definition' => 'Lord Mayor']],
        ]);

        $documents = $this->documents()->documents('p1');

        $this->assertCount(1, $documents, 'only the German file');
        $this->assertEquals(
            'Oberbürgermeister',
            $documents[0]->entries[0]['expansion'],
            'the German definition',
        );
    }

    public function testWithoutConfigurationThereAreNoDocuments(): void
    {
        $this->remove($this->configDir);

        $this->assertCount(0, $this->documents()->documents('p1'), 'no documents');
    }

    private function documents(?LoggerInterface $logger = null): GenAiConfigDocuments
    {
        $channel = $this->createResourceChannel();
        return $logger === null
            ? new GenAiConfigDocuments($channel, new GenAiDocumentFactory($channel))
            : new GenAiConfigDocuments($channel, new GenAiDocumentFactory($channel), $logger);
    }

    /**
     * @param array<mixed> $data
     */
    private function write(string $path, array $data): void
    {
        file_put_contents(
            $this->configDir . '/genai/' . $path,
            '<?php return ' . var_export($data, true) . ';',
        );
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function createResourceChannel(): ResourceChannel
    {
        return new ResourceChannel(
            '',
            'WWW',
            '',
            '',
            false,
            '',
            'de_DE',
            '',
            '',
            $this->configDir,
            'www',
            [],
            new DataBag([]),
            $this->createStub(ResourceTenant::class),
        );
    }
}
