<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Dto\Indexer\Link;
use Atoolo\GenAi\Dto\Indexer\LinkSection;
use Atoolo\GenAi\Dto\Indexer\TextSection;
use Atoolo\GenAi\Service\Indexer\ContentBeyondTitle;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentBeyondTitle::class)]
class ContentBeyondTitleTest extends TestCase
{
    public function testTextOfTitleWordsOnly(): void
    {
        $doc = $this->createArticle(
            'Deutschkurs A2 Frauenkurs Bad Cannstatt',
            '<p>Frauenkurs</p><p>A2 - Deutschkurs, Bad Cannstatt</p>',
        );

        $this->assertFalse(
            (new ContentBeyondTitle())->suffices($doc),
            'words of the title are no content',
        );
    }

    public function testShortText(): void
    {
        $doc = $this->createArticle('Sachbearbeitung', '<p>51 Jugendamt</p>');

        $this->assertFalse(
            (new ContentBeyondTitle())->suffices($doc),
            'too short to be content',
        );
    }

    public function testShortButConcreteText(): void
    {
        $doc = $this->createArticle(
            'Batterien',
            '<p>Entsorgungsweg: Rückgabe an Verkaufsstelle</p>',
        );

        $this->assertTrue(
            (new ContentBeyondTitle())->suffices($doc),
            'a short but concrete text is content',
        );
    }

    public function testWordsOfHeadlineAndKickerDoNotCount(): void
    {
        $doc = $this->createArticle(
            'Kurs',
            '<p>Integrationskurs Volkshochschule</p>',
        );
        $doc->headline = 'Integrationskurs';
        $doc->kicker = 'Volkshochschule';

        $this->assertFalse(
            (new ContentBeyondTitle())->suffices($doc),
            'the words of headline and kicker are no content',
        );
    }

    public function testWordsAreComparedCaseInsensitively(): void
    {
        $doc = $this->createArticle(
            'ÜBERSICHT STADTBEZIRKE',
            '<p>Übersicht Stadtbezirke</p>',
        );

        $this->assertFalse(
            (new ContentBeyondTitle())->suffices($doc),
            'the case should not matter',
        );
    }

    public function testAltTextOfAnImageCounts(): void
    {
        $doc = $this->createArticle(
            'Stadtplan',
            '<p><img src="plan.png" alt="Übersichtsplan der Innenstadt"></p>',
        );

        $this->assertTrue(
            (new ContentBeyondTitle())->suffices($doc),
            'the alt text of an image is content',
        );
    }

    public function testEntitiesAreDecoded(): void
    {
        $doc = $this->createArticle('Test', '<p>&nbsp;&amp;&nbsp;&shy;</p>');

        $this->assertFalse(
            (new ContentBeyondTitle())->suffices($doc),
            'entities are no letters',
        );
    }

    public function testLinkSectionDoesNotCount(): void
    {
        $doc = new GenAiDocument();
        $doc->title = 'Links';
        $doc->content = [
            new LinkSection('', [
                new Link(
                    'https://www.example.com',
                    'Eine sehr lange Beschriftung eines Links',
                ),
            ]),
        ];

        $this->assertFalse(
            (new ContentBeyondTitle())->suffices($doc),
            'links are no content',
        );
    }

    public function testIntroAndSectionHeadlineCount(): void
    {
        $doc = new GenAiDocument();
        $doc->title = 'Bürgerbüro';
        $doc->intro = '<p>Öffnungszeiten</p>';
        $doc->content = [new TextSection('Terminvereinbarung', '')];

        $this->assertTrue(
            (new ContentBeyondTitle())->suffices($doc),
            'intro and section headline are content',
        );
    }

    public function testMediumWithShortRawText(): void
    {
        $doc = new GenAiDocument();
        $doc->type = GenAiDocument::TYPE_MEDIA;
        $doc->title = 'Formular';
        $doc->rawText = 'Formular Seite 1';

        $this->assertFalse(
            (new ContentBeyondTitle())->suffices($doc),
            'a short raw text is no content',
        );
    }

    public function testMediumWithRawText(): void
    {
        $doc = new GenAiDocument();
        $doc->type = GenAiDocument::TYPE_MEDIA;
        $doc->title = 'Formular';
        $doc->rawText = 'Antrag auf Erteilung einer Parkerlaubnis';

        $this->assertTrue(
            (new ContentBeyondTitle())->suffices($doc),
            'the raw text of a medium is content',
        );
    }

    private function createArticle(string $title, string $html): GenAiDocument
    {
        $doc = new GenAiDocument();
        $doc->title = $title;
        $doc->content = [new TextSection('', $html)];
        return $doc;
    }
}
