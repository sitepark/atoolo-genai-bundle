<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Dto\Indexer\Category;
use Atoolo\GenAi\Dto\Indexer\Link;
use Atoolo\GenAi\Dto\Indexer\LinkSection;
use Atoolo\GenAi\Dto\Indexer\TextSection;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenAiDocument::class)]
class GenAiDocumentTest extends TestCase
{
    public function testArticleIsTheDefaultType(): void
    {
        $doc = new GenAiDocument();

        $this->assertEquals(
            'article',
            $doc->jsonSerialize()['type'],
            'a document should be an article until it is set to a medium',
        );
        $this->assertFalse($doc->isMedia(), 'should not be a medium');
    }

    public function testNullFieldsAreLeftOut(): void
    {
        $doc = new GenAiDocument();
        $doc->id = '123';

        $data = $doc->jsonSerialize();

        $this->assertArrayNotHasKey(
            'title',
            $data,
            'a field that was never set should not be sent',
        );
        $this->assertEquals('123', $data['id'], 'unexpected id');
    }

    public function testChannelIsSent(): void
    {
        $doc = new GenAiDocument();
        $doc->channel = 'www';

        $this->assertEquals(
            'www',
            $doc->jsonSerialize()['channel'],
            'unexpected channel',
        );
    }

    public function testDateIsFormattedAsAtom(): void
    {
        $doc = new GenAiDocument();
        $doc->date = new DateTime('2024-01-31T11:15:10+00:00');

        $this->assertEquals(
            '2024-01-31T11:15:10+00:00',
            $doc->jsonSerialize()['date'],
            'unexpected date format',
        );
    }

    public function testArticleSendsHeadlineAndContent(): void
    {
        $doc = new GenAiDocument();
        $doc->headline = 'Personalausweis';
        $doc->content = [
            new TextSection('Unterlagen', '<p>Ein Foto.</p>'),
            new LinkSection('Service', [new Link('/form', 'Antrag')]),
        ];
        $doc->rawText = 'should not be sent for an article';

        $data = $doc->jsonSerialize();

        $this->assertEquals(
            'Personalausweis',
            $data['headline'],
            'unexpected headline',
        );
        $this->assertEquals(
            [
                [
                    'type' => 'text',
                    'headline' => 'Unterlagen',
                    'html' => '<p>Ein Foto.</p>',
                ],
                [
                    'type' => 'links',
                    'headline' => 'Service',
                    'links' => [['url' => '/form', 'label' => 'Antrag']],
                ],
            ],
            $data['content'],
            'unexpected content sections',
        );
        $this->assertArrayNotHasKey(
            'rawText',
            $data,
            'an article should not send the raw text of a medium',
        );
    }

    public function testArticleSendsKickerAndIntro(): void
    {
        $doc = new GenAiDocument();
        $doc->kicker = 'Bürgerservice';
        $doc->headline = 'Personalausweis';
        $doc->intro = 'So beantragen Sie Ihren Ausweis.';

        $data = $doc->jsonSerialize();

        $this->assertEquals(
            'Bürgerservice',
            $data['kicker'],
            'unexpected kicker',
        );
        $this->assertEquals(
            'So beantragen Sie Ihren Ausweis.',
            $data['intro'],
            'unexpected intro',
        );
    }

    public function testArticleWithoutKickerAndIntroSendsNone(): void
    {
        $data = (new GenAiDocument())->jsonSerialize();

        $this->assertArrayNotHasKey('kicker', $data, 'unexpected kicker');
        $this->assertArrayNotHasKey('intro', $data, 'unexpected intro');
    }

    public function testKeywordsAreSent(): void
    {
        $doc = new GenAiDocument();
        $doc->addKeywords('Perso', 'Ausweis');

        $this->assertEquals(
            ['Perso', 'Ausweis'],
            $doc->jsonSerialize()['keywords'],
            'unexpected keywords',
        );
    }

    public function testWithoutKeywordsNoneAreSent(): void
    {
        $this->assertArrayNotHasKey(
            'keywords',
            (new GenAiDocument())->jsonSerialize(),
            'an empty list should not be sent',
        );
    }

    public function testAddKeywordsKeepsEveryKeywordOnce(): void
    {
        $doc = new GenAiDocument();
        $doc->addKeywords(' Perso ', '', 'Ausweis');
        $doc->addKeywords('Perso', '  ');

        $this->assertEquals(
            ['Perso', 'Ausweis'],
            $doc->keywords,
            'keywords should be trimmed, empty ones dropped, each kept once',
        );
    }

    public function testAMediumSendsItsKeywords(): void
    {
        $doc = new GenAiDocument();
        $doc->type = GenAiDocument::TYPE_MEDIA;
        $doc->addKeywords('Erntehelfer');

        $this->assertEquals(
            ['Erntehelfer'],
            $doc->jsonSerialize()['keywords'],
            'a medium should be found by its keywords as well',
        );
    }

    public function testMediaSendsRawTextOnly(): void
    {
        $doc = new GenAiDocument();
        $doc->type = GenAiDocument::TYPE_MEDIA;
        $doc->rawText = 'Der Text des PDF.';
        $doc->kicker = 'should not be sent for a medium';
        $doc->headline = 'should not be sent for a medium';
        $doc->intro = 'should not be sent for a medium';
        $doc->content = [new TextSection('', '<p>neither</p>')];

        $data = $doc->jsonSerialize();

        $this->assertTrue($doc->isMedia(), 'should be a medium');
        $this->assertEquals('media', $data['type'], 'unexpected type');
        $this->assertEquals(
            'Der Text des PDF.',
            $data['rawText'],
            'unexpected raw text',
        );
        $this->assertArrayNotHasKey(
            'headline',
            $data,
            'a medium has no headline',
        );
        $this->assertArrayNotHasKey(
            'kicker',
            $data,
            'a medium has no kicker',
        );
        $this->assertArrayNotHasKey(
            'intro',
            $data,
            'a medium has no intro',
        );
        $this->assertArrayNotHasKey(
            'content',
            $data,
            'a medium has no content sections',
        );
    }

    public function testEmptyListsAreLeftOut(): void
    {
        $data = (new GenAiDocument())->jsonSerialize();

        $this->assertArrayNotHasKey(
            'categories',
            $data,
            'empty categories should not be sent',
        );
        $this->assertArrayNotHasKey(
            'content',
            $data,
            'empty content should not be sent',
        );
    }

    public function testCategoriesAreSerialized(): void
    {
        $doc = new GenAiDocument();
        $doc->categories = [new Category('12', 'Dokumente')];

        $this->assertEquals(
            [['id' => '12', 'name' => 'Dokumente']],
            $doc->jsonSerialize()['categories'],
            'unexpected categories',
        );
    }
}
