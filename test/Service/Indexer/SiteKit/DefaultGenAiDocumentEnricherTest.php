<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer\SiteKit;

use Atoolo\GenAi\Dto\Indexer\LinkSection;
use Atoolo\GenAi\Dto\Indexer\TextSection;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\SiteKit\ContactPointSections;
use Atoolo\GenAi\Service\Indexer\SiteKit\DefaultGenAiDocumentEnricher;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\Exception\ResourceNotFoundException;
use Atoolo\Resource\Loader\SiteKitNavigationHierarchyLoader;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceLocation;
use Atoolo\Resource\ResourceTenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(DefaultGenAiDocumentEnricher::class)]
#[UsesClass(ContactPointSections::class)]
class DefaultGenAiDocumentEnricherTest extends TestCase
{
    private DefaultGenAiDocumentEnricher $enricher;

    private SiteKitNavigationHierarchyLoader&MockObject $navigationLoader;

    public function setUp(): void
    {
        $this->navigationLoader = $this->createMock(
            SiteKitNavigationHierarchyLoader::class,
        );
        $this->enricher = new DefaultGenAiDocumentEnricher(
            $this->navigationLoader,
            $this->createResourceChannel(),
            'internal',
        );
    }

    public function testCleanup(): void
    {
        $this->navigationLoader->expects($this->once())->method('cleanup');
        $this->enricher->cleanup();
    }

    public function testEnrichCommonFields(): void
    {
        $doc = $this->enrichWithResource($this->createResource([
            'id' => '123',
            'objectType' => 'news',
            'url' => '/a/b.php',
            'base' => ['title' => 'A title', 'date' => 1707549836],
        ]));

        $this->assertEquals('123', $doc->id, 'unexpected id');
        $this->assertEquals('internal', $doc->source, 'unexpected source');
        $this->assertEquals(
            'progress-id',
            $doc->processId,
            'unexpected process id',
        );
        $this->assertEquals(
            'news',
            $doc->objectType,
            'unexpected object type',
        );
        $this->assertEquals('A title', $doc->title, 'unexpected title');
        $this->assertEquals(
            'https://www.example.com/a/b.php',
            $doc->url,
            'the url should be absolute, with the host of the channel',
        );
        $this->assertEquals(
            '2024-02-10',
            $doc->date?->format('Y-m-d'),
            'unexpected date',
        );
    }

    public function testAMediumInAMediaContainerGetsACompositeId(): void
    {
        $doc = $this->enrichWithResource($this->createResource([
            'id' => '123',
            'media' => true,
            'mediaContainer' => ['id' => 456],
        ]));

        $this->assertEquals('456-123', $doc->id, 'unexpected id');
    }

    public function testAMediumWithoutAMediaContainerKeepsItsId(): void
    {
        $doc = $this->enrichWithResource($this->createResource([
            'id' => '123',
            'media' => true,
        ]));

        $this->assertEquals('123', $doc->id, 'unexpected id');
    }

    public function testAnArticleIgnoresTheMediaContainer(): void
    {
        $doc = $this->enrichWithResource($this->createResource([
            'id' => '123',
            'mediaContainer' => ['id' => 456],
        ]));

        $this->assertEquals('123', $doc->id, 'unexpected id');
    }

    public function testKeywordsAndBoostKeywords(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'keywords' => ['Perso', 'Ausweis'],
                'boostKeywords' => ['Ausweis', 'Personalausweis'],
            ],
        ]);

        $this->assertEquals(
            ['Perso', 'Ausweis', 'Personalausweis'],
            $doc->keywords,
            'keywords and boost keywords should be merged, each once',
        );
    }

    public function testAMediumGetsItsKeywords(): void
    {
        $doc = $this->enrichWithData([
            'media' => true,
            'metadata' => ['keywords' => ['Erntehelfer']],
        ]);

        $this->assertEquals(
            ['Erntehelfer'],
            $doc->keywords,
            'a medium should be found by its keywords as well',
        );
    }

    public function testKeywordsThatAreNoStringsAreSkipped(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => ['keywords' => ['Perso', 7, null, ['x']]],
        ]);

        $this->assertEquals(['Perso'], $doc->keywords, 'unexpected keywords');
    }

    public function testEnrichWithoutDate(): void
    {
        $doc = $this->enrichWithResource($this->createResource([
            'objectType' => 'news',
        ]));

        $this->assertNull($doc->date, 'without a date none should be set');
    }

    public function testTheDateOfAnUndatedTypeIsLeftOut(): void
    {
        $doc = $this->enrichWithResource($this->createResource([
            'objectType' => 'citygovProduct',
            'base' => ['date' => 1707549836],
        ]));

        $this->assertNull(
            $doc->date,
            'the date of a type whose age says nothing should be left out',
        );
    }

    #[TestWith(['media'])]
    #[TestWith(['embedded-media'])]
    public function testMediaAreDated(string $objectType): void
    {
        $doc = $this->enrichWithResource($this->createResource([
            'objectType' => $objectType,
            'media' => true,
            'base' => ['date' => 1707549836],
        ]));

        $this->assertEquals(
            '2024-02-10',
            $doc->date?->format('Y-m-d'),
            'a medium should keep its date',
        );
    }

    public function testConfiguredTypesAreDated(): void
    {
        $enricher = new DefaultGenAiDocumentEnricher(
            $this->navigationLoader,
            $this->createResourceChannel(),
            'internal',
            ['eventsCalendar-event'],
        );

        /** @var GenAiDocument $doc */
        $doc = $enricher->enrichDocument(
            $this->createResource([
                'objectType' => 'eventsCalendar-event',
                'base' => ['date' => 1707549836],
            ]),
            new GenAiDocument(),
            'progress-id',
        );

        $this->assertEquals(
            '2024-02-10',
            $doc->date?->format('Y-m-d'),
            'a configured type should keep its date',
        );
    }

    public function testMediaUrlWins(): void
    {
        $doc = $this->enrichWithData([
            'url' => '/a/b.php',
            'mediaUrl' => '/media/1.pdf',
        ]);

        $this->assertEquals(
            'https://www.example.com/media/1.pdf',
            $doc->url,
            'the media url should win over the resource url',
        );
    }

    public function testUrlWithAHostIsKept(): void
    {
        $doc = $this->enrichWithData([
            'url' => '/a/b.php',
            'mediaUrl' => 'https://media.example.com/1.pdf',
        ]);

        $this->assertEquals(
            'https://media.example.com/1.pdf',
            $doc->url,
            'a url that already names a host should be kept',
        );
    }

    public function testProtocolRelativeUrlIsKept(): void
    {
        $doc = $this->enrichWithData([
            'url' => '//media.example.com/1.pdf',
        ]);

        $this->assertEquals(
            '//media.example.com/1.pdf',
            $doc->url,
            'a protocol relative url should be kept',
        );
    }

    public function testRelativeUrlGetsASlash(): void
    {
        $doc = $this->enrichWithData([
            'url' => 'a/b.php',
        ]);

        $this->assertEquals(
            'https://www.example.com/a/b.php',
            $doc->url,
            'a url without a leading slash should still be absolute',
        );
    }

    public function testWithoutUrlNoneIsBuilt(): void
    {
        $doc = $this->enrichWithData([]);

        $this->assertEquals(
            '',
            $doc->url,
            'without a url none should be built',
        );
    }

    public function testAResourceIsAnArticle(): void
    {
        $doc = $this->enrichWithData([]);

        $this->assertEquals(
            GenAiDocument::TYPE_ARTICLE,
            $doc->type,
            'unexpected type',
        );
    }

    public function testAMediumIsSentWithItsExtractedText(): void
    {
        $doc = $this->enrichWithData([
            'media' => true,
            'searchindexdata' => ['content' => ' Der Text des PDF. '],
            'base' => ['title' => 'Formular'],
        ]);

        $this->assertEquals(
            GenAiDocument::TYPE_MEDIA,
            $doc->type,
            'unexpected type',
        );
        $this->assertEquals(
            'Der Text des PDF.',
            $doc->rawText,
            'unexpected raw text',
        );
        $this->assertEmpty(
            $doc->content,
            'a medium carries no content sections',
        );
    }

    public function testAMediumWithoutTextKeepsNone(): void
    {
        $doc = $this->enrichWithData(['media' => true]);

        $this->assertNull($doc->rawText, 'unexpected raw text');
    }

    public function testSearchTipIsNotEnrichedFurther(): void
    {
        $doc = $this->enrichWithResource($this->createResource([
            'objectType' => 'searchTip',
            'base' => ['title' => 'A title'],
            'content' => $this->contentWith([$this->textBlock('H', '<p>T</p>')]),
        ]));

        $this->assertEquals('A title', $doc->title, 'unexpected title');
        $this->assertEmpty(
            $doc->content,
            'a search tip carries no content',
        );
    }

    public function testHeadlineFromTeaser(): void
    {
        $doc = $this->enrichWithData([
            'base' => [
                'title' => 'A title',
                'teaser' => ['headline' => 'Teaser headline'],
            ],
            'metadata' => ['headline' => 'Metadata headline'],
        ]);

        $this->assertEquals(
            'Teaser headline',
            $doc->headline,
            'the teaser headline should win',
        );
    }

    public function testHeadlineFromMetadata(): void
    {
        $doc = $this->enrichWithData([
            'base' => ['title' => 'A title'],
            'metadata' => ['headline' => 'Metadata headline'],
        ]);

        $this->assertEquals(
            'Metadata headline',
            $doc->headline,
            'unexpected headline',
        );
    }

    public function testHeadlineFallsBackToTheTitle(): void
    {
        $doc = $this->enrichWithData(['base' => ['title' => 'A title']]);

        $this->assertEquals(
            'A title',
            $doc->headline,
            'the title should be the last resort',
        );
    }

    public function testKickerFromTeaser(): void
    {
        $doc = $this->enrichWithData([
            'base' => [
                'kicker' => 'Own kicker',
                'teaser' => ['kicker' => 'Teaser kicker'],
            ],
        ]);

        $this->assertEquals(
            'Teaser kicker',
            $doc->kicker,
            'the teaser kicker should win',
        );
    }

    public function testKickerOfTheResource(): void
    {
        $this->navigationLoader
            ->expects($this->never())
            ->method('loadPrimaryParent');

        $doc = $this->enrichWithData(['base' => ['kicker' => 'Own kicker']]);

        $this->assertEquals('Own kicker', $doc->kicker, 'unexpected kicker');
    }

    public function testKickerIsInheritedFromTheNavigation(): void
    {
        $parent = $this->createResource(['url' => '/parent.php']);
        $grandParent = $this->createResource([
            'url' => '/grand-parent.php',
            'base' => ['kicker' => 'Inherited kicker'],
        ]);
        $this->navigationLoader
            ->expects($this->exactly(2))
            ->method('loadPrimaryParent')
            ->willReturnCallback(
                static fn(ResourceLocation $location): ?Resource
                    => match ($location->location) {
                        '/page.php' => $parent,
                        '/parent.php' => $grandParent,
                        default => null,
                    },
            );

        $doc = $this->enrichWithData(['url' => '/page.php']);

        $this->assertEquals(
            'Inherited kicker',
            $doc->kicker,
            'the kicker of the nearest ancestor should be used',
        );
    }

    public function testWithoutAnyKickerThereIsNone(): void
    {
        $doc = $this->enrichWithData(['base' => ['kicker' => '  ']]);

        $this->assertNull($doc->kicker, 'unexpected kicker');
    }

    public function testUnloadableParentLeavesNoKicker(): void
    {
        $this->navigationLoader
            ->method('loadPrimaryParent')
            ->willThrowException(
                new ResourceNotFoundException(
                    ResourceLocation::of('/parent.php'),
                ),
            );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $this->enricher->setLogger($logger);

        $doc = $this->enrichWithData(['url' => '/page.php']);

        $this->assertNull($doc->kicker, 'unexpected kicker');
    }

    public function testIntroFromMetadata(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'intro' => 'The intro',
                'description' => 'The description',
            ],
        ]);

        $this->assertEquals(
            '<p>The intro</p>',
            $doc->intro,
            'the intro should win',
        );
    }

    public function testIntroFallsBackToTheDescription(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => ['description' => 'The description'],
        ]);

        $this->assertEquals(
            '<p>The description</p>',
            $doc->intro,
            'the description should be the fallback',
        );
    }

    public function testIntroIsEscaped(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => ['intro' => 'Bus & Bahn <kostenlos>'],
        ]);

        $this->assertEquals(
            '<p>Bus &amp; Bahn &lt;kostenlos&gt;</p>',
            $doc->intro,
            'the plain text of the CMS should not be read as markup',
        );
    }

    public function testWithoutIntroThereIsNone(): void
    {
        $doc = $this->enrichWithData([]);

        $this->assertNull($doc->intro, 'unexpected intro');
    }

    public function testAMediumHasNoKickerAndNoIntro(): void
    {
        $doc = $this->enrichWithData([
            'media' => true,
            'base' => ['kicker' => 'Kicker'],
            'metadata' => ['intro' => 'Intro'],
        ]);

        $this->assertNull($doc->kicker, 'a medium has no kicker');
        $this->assertNull($doc->intro, 'a medium has no intro');
    }

    public function testTextSectionKeepsTheHtml(): void
    {
        $doc = $this->enrichWithData([
            'content' => $this->contentWith([
                $this->textBlock('Öffnungszeiten', '<p>Montags zu.</p>'),
            ]),
        ]);

        $this->assertCount(1, $doc->content, 'one section expected');
        $section = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            'Öffnungszeiten',
            $section->headline,
            'unexpected headline',
        );
        $this->assertEquals(
            '<p>Montags zu.</p>',
            $section->html,
            'the markup of the editor should be kept',
        );
    }

    public function testEmptyRichTextIsNoSection(): void
    {
        $doc = $this->enrichWithData([
            'content' => $this->contentWith([
                $this->textBlock('Leer', '   '),
            ]),
        ]);

        $this->assertEmpty($doc->content, 'an empty text is no section');
    }

    public function testBlocksWithoutTextOrLinksAreSkipped(): void
    {
        $doc = $this->enrichWithData([
            'content' => $this->contentWith([
                [
                    'type' => 'separation',
                    'model' => ['modelType' => 'content.separation'],
                ],
                ['type' => 'broken'],
            ]),
        ]);

        $this->assertEmpty($doc->content, 'unexpected sections');
    }

    public function testLinkSection(): void
    {
        $doc = $this->enrichWithData([
            'content' => $this->contentWith([
                [
                    'type' => 'linkList',
                    'model' => [
                        'modelType' => 'content.linkList',
                        'headline' => 'Service',
                        'items' => [
                            [
                                'modelType' => 'content.link.link',
                                'url' => '/form',
                                'label' => '<b>Antrag</b>',
                            ],
                            [
                                'modelType' => 'content.link.link',
                                'url' => '/plain',
                            ],
                            ['modelType' => 'content.link.link'],
                            ['modelType' => 'something.else'],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->assertCount(1, $doc->content, 'one section expected');
        $section = $doc->content[0];
        $this->assertInstanceOf(LinkSection::class, $section);
        $this->assertEquals(
            'Service',
            $section->headline,
            'unexpected headline',
        );
        $this->assertCount(2, $section->links, 'a link needs a url');
        $this->assertEquals(
            '/form',
            $section->links[0]->url,
            'unexpected url',
        );
        $this->assertEquals(
            'Antrag',
            $section->links[0]->label,
            'the label should be plain text',
        );
        $this->assertEquals(
            '',
            $section->links[1]->label,
            'a link without a label keeps none',
        );
    }

    public function testEmptyLinkListIsNoSection(): void
    {
        $doc = $this->enrichWithData([
            'content' => $this->contentWith([
                [
                    'type' => 'linkList',
                    'model' => [
                        'modelType' => 'content.linkList',
                        'items' => [],
                    ],
                ],
                [
                    'type' => 'linkList',
                    'model' => ['modelType' => 'content.linkList'],
                ],
            ]),
        ]);

        $this->assertEmpty($doc->content, 'an empty link list is no section');
    }

    public function testQuoteBecomesABlockquote(): void
    {
        $doc = $this->enrichWithData([
            'content' => $this->contentWith([
                [
                    'type' => 'quote',
                    'model' => [
                        'modelType' => 'content.quote',
                        'quote' => 'Ein Zitat.',
                        'citation' => 'Wer es sagte',
                    ],
                ],
                [
                    'type' => 'quote',
                    'model' => [
                        'modelType' => 'content.quote',
                        'citation' => 'ohne Zitat',
                    ],
                ],
            ]),
        ]);

        $this->assertCount(1, $doc->content, 'one section expected');
        $section = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            '<blockquote>Ein Zitat.<cite>Wer es sagte</cite></blockquote>',
            $section->html,
            'unexpected blockquote',
        );
    }

    public function testQuoteWithoutCitation(): void
    {
        $doc = $this->enrichWithData([
            'content' => $this->contentWith([
                [
                    'type' => 'quote',
                    'model' => [
                        'modelType' => 'content.quote',
                        'quote' => 'Ein Zitat.',
                    ],
                ],
            ]),
        ]);

        $section = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            '<blockquote>Ein Zitat.</blockquote>',
            $section->html,
            'without a citation none should be rendered',
        );
    }

    public function testNestedBlocksAreFoundInOrder(): void
    {
        $doc = $this->enrichWithData([
            'content' => [
                'type' => 'ROOT',
                'items' => [
                    [
                        'type' => 'main',
                        'items' => [
                            $this->textBlock('One', '<p>1</p>'),
                            [
                                'type' => 'columns',
                                'items' => [
                                    $this->textBlock('Two', '<p>2</p>'),
                                    $this->textBlock('Three', '<p>3</p>'),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertEquals(
            ['One', 'Two', 'Three'],
            array_map(
                static fn($section): string => $section->headline,
                $doc->content,
            ),
            'the sections should keep the order of the editor',
        );
    }

    public function testSectionHeadlineIsPutInFrontOfItsBlocks(): void
    {
        $doc = $this->enrichWithData([
            'content' => $this->contentWith([
                $this->sectionBlock('Ausbildung', [
                    $this->textBlock('', '<p>1</p>'),
                    $this->textBlock('Voraussetzungen', '<p>2</p>'),
                    $this->sectionBlock('Ablauf', [
                        $this->textBlock('Prüfung', '<p>3</p>'),
                    ]),
                ]),
                $this->sectionBlock('', [
                    $this->textBlock('Kosten', '<p>4</p>'),
                ]),
                $this->textBlock('Danach', '<p>5</p>'),
            ]),
        ]);

        $this->assertEquals(
            [
                'Ausbildung',
                'Ausbildung › Voraussetzungen',
                'Ausbildung › Ablauf › Prüfung',
                'Kosten',
                'Danach',
            ],
            array_map(
                static fn($section): string => $section->headline,
                $doc->content,
            ),
            'the headline of a section should lead those of its blocks',
        );
    }

    public function testSearchIndexDataLeadsTheContent(): void
    {
        $doc = $this->enrichWithData([
            'searchindexdata' => ['content' => 'Zusätzlicher Suchtext'],
            'content' => $this->contentWith([
                $this->textBlock('Zwei', '<p>2</p>'),
            ]),
        ]);

        $this->assertCount(2, $doc->content, 'two sections expected');
        $first = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $first);
        $this->assertEquals(
            'Zusätzlicher Suchtext',
            $first->html,
            'what the CMS prepared should come first',
        );
    }

    public function testContactPointBecomesASectionOfItsOwn(): void
    {
        $doc = $this->enrichWithData([
            'content' => $this->contentWith([
                $this->textBlock('Unterlagen', '<p>Ein Foto.</p>'),
            ]),
            'metadata' => [
                'contactPoint' => [
                    'contactData' => [
                        'phoneList' => [
                            ['phone' => ['nationalNumber' => '0251 4920']],
                            [
                                'phone' => [
                                    'type' => 'fax',
                                    'nationalNumber' => '0251 4921',
                                ],
                            ],
                        ],
                        'emailList' => [['email' => 'info@example.com']],
                        'room' => '204',
                    ],
                    'addressData' => [
                        'buildingName' => 'Rathaus',
                        'street' => 'Musterstraße',
                        'housenumber' => '1',
                        'postalCode' => '48143',
                        'city' => 'Münster',
                        'publicTransportationNotice' => 'Linie 14.',
                    ],
                ],
            ],
        ]);

        $this->assertCount(2, $doc->content, 'the contact should be a section');
        $section = $doc->content[1];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            'Kontakt',
            $section->headline,
            'unexpected headline',
        );
        $this->assertEquals(
            '<ul>'
            . '<li>Telefon: 0251 4920</li>'
            . '<li>Fax: 0251 4921</li>'
            . '<li>E-Mail: info@example.com</li>'
            . '<li>Adresse: Rathaus, Musterstraße 1, 48143 Münster</li>'
            . '<li>Raum: 204</li>'
            . '</ul>'
            . '<p>Anfahrt: Linie 14.</p>',
            $section->html,
            'unexpected contact section',
        );
    }

    public function testWithoutAContactPointThereIsNoSection(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'contactPoint' => [
                    'contactData' => [
                        'phoneList' => [['phone' => []]],
                        'emailList' => [['email' => '  ']],
                    ],
                    'addressData' => [],
                ],
            ],
        ]);

        $this->assertEmpty(
            $doc->content,
            'an empty contact point should not become a section',
        );
    }

    public function testContactHeadlineWins(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'contactPoint' => [
                    'headline' => 'Ihre Ansprechpartnerin',
                    'contactData' => [
                        'emailList' => [['email' => 'info@example.com']],
                    ],
                ],
            ],
        ]);

        $section = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            'Ihre Ansprechpartnerin',
            $section->headline,
            'the headline of the contact point should be used',
        );
    }

    public function testPhoneNumberIsAssembledFromItsParts(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'contactPoint' => [
                    'contactData' => [
                        'phoneList' => [
                            [
                                'phone' => [
                                    'countryCode' => '49',
                                    'areaCode' => '251',
                                    'localNumber' => '4920',
                                    'extension' => '17',
                                ],
                            ],
                            [
                                'phone' => [
                                    'areaCode' => '251',
                                    'localNumber' => '4921',
                                ],
                            ],
                            ['phone' => ['localNumber' => '4922']],
                            [
                                'phone' => [
                                    'internationalNumber' => '+49 251 4923',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $section = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            '<ul>'
            . '<li>Telefon: +49 251 4920-17</li>'
            . '<li>Telefon: 0251 4921</li>'
            . '<li>Telefon: 4922</li>'
            . '<li>Telefon: +49 251 4923</li>'
            . '</ul>',
            $section->html,
            'unexpected phone numbers',
        );
    }

    public function testReadablePhoneNumberKeepsItsExtension(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'contactPoint' => [
                    'contactData' => [
                        'phoneList' => [
                            [
                                'phone' => [
                                    'nationalNumber' => '0711 216-93710',
                                    'areaCode' => '711',
                                    'localNumber' => '216',
                                    'extension' => '93710',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $section = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            '<ul><li>Telefon: 0711 216-93710</li></ul>',
            $section->html,
            'the extension should not be appended twice',
        );
    }

    public function testOpeningHoursBecomeASection(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'contactPoint' => [
                    'openingHours' => [
                        'weekBlockList' => [
                            [
                                'headline' => null,
                                'weekSeriesList' => [
                                    [
                                        'dayOfWeekList' => ['MONDAY'],
                                        'timeRangeList' => [
                                            ['start' => '08:30', 'end' => '12:00'],
                                            ['start' => '14:00', 'end' => '16:00'],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'weekSeriesList' => [
                                    [
                                        'dayOfWeekList' => ['TUESDAY', 'FRIDAY'],
                                        'timeRangeList' => [
                                            ['start' => '08:30', 'end' => '13:00'],
                                        ],
                                        'notice' => 'nur mit Termin',
                                    ],
                                ],
                            ],
                            [
                                'headline' => 'Sommer & Ferien',
                                'notice' => 'Juli bis August',
                                'weekSeriesList' => [
                                    [
                                        'dayOfWeekList' => ['SATURDAY'],
                                        'timeRangeList' => [
                                            ['start' => '10:00', 'end' => '12:00'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'additionalText' => [
                            'text' => '<p>Aktuelle <strong>Wartezeiten</strong></p>',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $doc->content, 'the opening hours should be a section');
        $section = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            'Öffnungszeiten',
            $section->headline,
            'unexpected headline',
        );
        $this->assertEquals(
            '<ul>'
            . '<li>Montag: 08:30 - 12:00 Uhr und 14:00 - 16:00 Uhr</li>'
            . '<li>Dienstag: 08:30 - 13:00 Uhr (nur mit Termin)</li>'
            . '<li>Freitag: 08:30 - 13:00 Uhr (nur mit Termin)</li>'
            . '</ul>'
            . '<p>Sommer &amp; Ferien</p>'
            . '<ul><li>Samstag: 10:00 - 12:00 Uhr</li></ul>'
            . '<p>Juli bis August</p>'
            . '<p>Aktuelle <strong>Wartezeiten</strong></p>',
            $section->html,
            'a block without headline and notice should continue the one before',
        );
    }

    public function testEmptyOpeningHoursAreSkipped(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'contactPoint' => [
                    'openingHours' => [
                        'weekBlockList' => [
                            [
                                'weekSeriesList' => [
                                    [
                                        'dayOfWeekList' => ['MONDAY'],
                                        'timeRangeList' => [],
                                    ],
                                ],
                            ],
                        ],
                        'additionalText' => null,
                    ],
                ],
            ],
        ]);

        $this->assertEmpty(
            $doc->content,
            'opening hours without times should not become a section',
        );
    }

    public function testContactTextIsEscaped(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'contactPoint' => [
                    'addressData' => [
                        'buildingName' => 'Amt für Bau & Verkehr',
                        'notice' => 'Montags <b>geschlossen</b>',
                    ],
                ],
            ],
        ]);

        $section = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            '<ul><li>Adresse: Amt für Bau &amp; Verkehr</li></ul>'
            . '<p>Hinweis: Montags &lt;b&gt;geschlossen&lt;/b&gt;</p>',
            $section->html,
            'plain text of the CMS should not become markup',
        );
    }

    public function testPostOfficeBoxAndAccessibility(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'contactPoint' => [
                    'addressData' => [
                        'postOfficeBoxData' => ['buildingName' => 'Postfach 1'],
                        'accessibleDescription' => 'Rampe vorhanden.',
                    ],
                ],
            ],
        ]);

        $section = $doc->content[0];
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            '<ul><li>Postfach: Postfach 1</li></ul>'
            . '<p>Barrierefreiheit: Rampe vorhanden.</p>',
            $section->html,
            'unexpected contact section',
        );
    }

    public function testAMediumHasNoContactSection(): void
    {
        $doc = $this->enrichWithData([
            'media' => true,
            'searchindexdata' => ['content' => 'Der Text des PDF.'],
            'metadata' => [
                'contactPoint' => [
                    'contactData' => [
                        'emailList' => [['email' => 'info@example.com']],
                    ],
                ],
            ],
        ]);

        $this->assertEmpty($doc->content, 'a medium carries no sections');
    }

    public function testCategoriesWithTheirNames(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'categories' => [
                    ['id' => 12, 'name' => 'Dokumente'],
                    ['id' => 12, 'name' => 'Doppelt'],
                ],
            ],
        ]);

        $this->assertCount(1, $doc->categories, 'a category should be unique');
        $this->assertEquals('12', $doc->categories[0]->id, 'unexpected id');
        $this->assertEquals(
            'Dokumente',
            $doc->categories[0]->name,
            'unexpected name',
        );
        $this->assertNull(
            $doc->categories[0]->parent,
            'SiteKit does not record which ancestor belongs to which category',
        );
    }

    public function testCategoryNameFromTheCategoryResource(): void
    {
        $this->navigationLoader
            ->expects($this->once())
            ->method('load')
            ->willReturn($this->createResource([
                'base' => ['title' => 'Bürgerservice'],
            ]));

        $doc = $this->enrichWithData([
            'metadata' => [
                'categories' => [
                    ['id' => 3, 'name' => 'fallback', 'url' => '/cat/3.php'],
                    ['id' => 4, 'name' => 'fallback', 'url' => '/cat/3.php'],
                ],
            ],
        ]);

        $this->assertEquals(
            'Bürgerservice',
            $doc->categories[0]->name,
            'the title of the category resource should win',
        );
        $this->assertEquals(
            'Bürgerservice',
            $doc->categories[1]->name,
            'a category resource should only be loaded once',
        );
    }

    public function testUnloadableCategoryFallsBackToItsName(): void
    {
        $this->navigationLoader
            ->method('load')
            ->willThrowException(
                new ResourceNotFoundException(
                    ResourceLocation::of('/cat/3.php'),
                ),
            );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $this->enricher->setLogger($logger);

        $doc = $this->enrichWithData([
            'metadata' => [
                'categories' => [
                    ['id' => 3, 'name' => 'Bürgerservice', 'url' => '/c.php'],
                ],
            ],
        ]);

        $this->assertEquals(
            'Bürgerservice',
            $doc->categories[0]->name,
            'unexpected name',
        );
    }

    public function testAncestorIdsAreAddedAsCategoriesOfTheirOwn(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'categories' => [['id' => 12, 'name' => 'Dokumente']],
                'categoriesPath' => [['id' => 12], ['id' => 3]],
            ],
        ]);

        $this->assertEquals(
            ['12', '3'],
            array_map(
                static fn($category): string => $category->id,
                $doc->categories,
            ),
            'the ancestors should be sent so that they can be filtered on',
        );
        $this->assertEquals(
            '',
            $doc->categories[1]->name,
            'a nameless ancestor is only there for the filter',
        );
    }

    public function testCategoryWithoutIdIsSkipped(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => ['categories' => [['name' => 'Namenlos']]],
        ]);

        $this->assertEmpty($doc->categories, 'unexpected categories');
    }

    /**
     * @param array<array<string,mixed>> $blocks
     * @return array<string,mixed>
     */
    private function contentWith(array $blocks): array
    {
        return [
            'type' => 'ROOT',
            'items' => [['type' => 'main', 'items' => $blocks]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function textBlock(string $headline, string $html): array
    {
        return [
            'type' => 'text',
            'model' => [
                'modelType' => 'content.text',
                'headline' => $headline,
                'richText' => [
                    'modelType' => 'html.richText',
                    'text' => $html,
                ],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return array<string,mixed>
     */
    private function sectionBlock(string $headline, array $blocks): array
    {
        return [
            'type' => 'section',
            'model' => [
                'modelType' => 'content.section',
                'headline' => $headline,
                'inToc' => false,
                'collapsible' => true,
                'collapsed' => true,
            ],
            'items' => $blocks,
        ];
    }

    private function enrichWithResource(Resource $resource): GenAiDocument
    {
        /** @var GenAiDocument $doc */
        $doc = $this->enricher->enrichDocument(
            $resource,
            new GenAiDocument(),
            'progress-id',
        );
        return $doc;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function enrichWithData(array $data): GenAiDocument
    {
        return $this->enrichWithResource($this->createResource($data));
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

    /**
     * @param array<string,mixed> $data
     */
    private function createResource(array $data): Resource
    {
        return new Resource(
            is_string($data['url'] ?? null) ? $data['url'] : '',
            is_string($data['url'] ?? null) ? $data['url'] : '',
            is_string($data['id'] ?? null) ? $data['id'] : '123',
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['objectType'] ?? null) ? $data['objectType'] : '',
            ResourceLanguage::of(
                is_string($data['locale'] ?? null) ? $data['locale'] : '',
            ),
            new DataBag($data),
        );
    }
}
