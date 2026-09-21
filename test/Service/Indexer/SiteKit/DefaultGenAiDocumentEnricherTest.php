<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer\SiteKit;

use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\SiteKit\DefaultGenAiDocumentEnricher;
use Atoolo\Index\Exception\DocumentEnrichingException;
use Atoolo\Index\Service\Indexer\ContentCollector;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\Exception\InvalidResourceException;
use Atoolo\Resource\Loader\SiteKitNavigationHierarchyLoader;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLanguage;
use DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DefaultGenAiDocumentEnricherTest extends TestCase
{
    private DefaultGenAiDocumentEnricher $enricher;

    private SiteKitNavigationHierarchyLoader&MockObject $navigationLoader;

    public function setUp(): void
    {
        $this->navigationLoader = $this->createMock(
            SiteKitNavigationHierarchyLoader::class,
        );
        $this->navigationLoader
            ->method('loadRoot')
            ->willReturnCallback(function ($location) {
                if ($location->location === 'throwException') {
                    throw new InvalidResourceException($location);
                }
                return $this->createResource([
                    'siteGroup' => ['id' => 999],
                ]);
            });
        $contentCollector = $this->createStub(ContentCollector::class);
        $contentCollector
            ->method('collect')
            ->willReturn('collected content');

        $this->enricher = new DefaultGenAiDocumentEnricher(
            $this->navigationLoader,
            $contentCollector,
            'genai',
        );
    }

    public function testCleanup(): void
    {
        $this->navigationLoader->expects($this->once())
            ->method('cleanup');
        $this->enricher->cleanup();
    }

    public function testEnrichSpId(): void
    {
        $resource = new Resource(
            '',
            '123',
            '',
            '',
            ResourceLanguage::default(),
            new DataBag([]),
        );
        $doc = $this->enrichWithResource($resource);
        $this->assertEquals('123', $doc->id, 'unexpected id');
    }

    public function testEnrichObjectType(): void
    {
        $resource = $this->createResource([
            'objectType' => 'test',
        ]);
        $doc = $this->enrichWithResource($resource);
        $this->assertEquals(
            'test',
            $doc->object_type,
            'unexpected objectType',
        );
    }

    public function testEnrichTitle(): void
    {
        $doc = $this->enrichWithData(['base' => ['title' => 'abc']]);
        $this->assertEquals('abc', $doc->title, 'unexpected title');
    }

    public function testEnrichDescriptionWithInto(): void
    {
        $doc = $this->enrichWithData(['metadata' => ['intro' => 'abc']]);
        $this->assertEquals(
            'abc',
            $doc->description,
            'unexpected description',
        );
    }
    public function testEnrichDescriptionWithIntoAndDescription(): void
    {
        $doc = $this->enrichWithData(
            ['metadata'
                => [
                    'intro' => 'abc',
                    'description' => 'def',
                ],
            ],
        );
        $this->assertEquals(
            'abc',
            $doc->description,
            'unexpected description',
        );
    }
    public function testEnrichDescriptionWithoutInto(): void
    {
        $doc = $this->enrichWithData(
            ['metadata'
                => [
                    'description' => 'def',
                ],
            ],
        );
        $this->assertEquals(
            'def',
            $doc->description,
            'unexpected description',
        );
    }

    public function testEnrichCrawlProcessId(): void
    {
        $resource = $this->createResource([]);
        $doc = $this->enricher->enrichDocument(
            $resource,
            new GenAiDocument(),
            'progress-id',
        );
        $this->assertEquals(
            $doc->process_id,
            'progress-id',
            'unexpected progress id',
        );
    }

    public function testEnrichUrl(): void
    {
        $doc = $this->enrichWithData(['url' => '/test.php']);
        $this->assertEquals(
            '/test.php',
            $doc->url,
            'unexpected url',
        );
    }

    public function testEnrichMediaUrl(): void
    {
        $doc = $this->enrichWithData(['mediaUrl' => '/test.php']);
        $this->assertEquals(
            '/test.php',
            $doc->url,
            'unexpected url',
        );
    }

    public function testEnrichSpContentType(): void
    {
        $doc = $this->enrichWithData([
            'objectType' => 'content',
            'contentSectionTypes' => ['text', 'linkList'],
            'base' => [
                'teaser' => [
                    'headline' => 'test',
                    'image' => [
                        'copyright' => 'test',
                    ],
                    'text' => 'test',
                ],
            ],
        ]);
        $this->assertEquals(
            [
                'content',
                'article',
                'text',
                'linkList',
                'teaserImage',
                'teaserImageCopyright',
                'teaserHeadline',
                'teaserText',
            ],
            $doc->content_types,
            'unexpected contenttype',
        );
    }

    public function testEnrichDefaultLanguage(): void
    {
        $doc = $this->enrichWithData([]);
        $this->assertEquals(
            'de',
            $doc->language,
            'unexpected language',
        );
    }

    public function testEnrichLanguage(): void
    {
        $doc = $this->enrichWithData(['locale' => 'en_US']);
        $this->assertEquals(
            'en',
            $doc->language,
            'unexpected language',
        );
    }

    public function testEnrichLanguageWithShortLocale(): void
    {
        $doc = $this->enrichWithData(['locale' => 'en']);
        $this->assertEquals(
            'en',
            $doc->language,
            'unexpected language',
        );
    }

    public function testEnrichLanguageOverGroupPath(): void
    {
        $doc = $this->enrichWithData([
            'groupPath' => [
                ['id' => 1, 'locale' => 'fr_FR'],
                ['id' => 2, 'locale' => 'it_IT'],
            ],
        ]);
        $this->assertEquals(
            'it',
            $doc->language,
            'unexpected language',
        );
    }

    public function testEnrichChanged(): void
    {
        $doc = $this->enrichWithData(['changed' => 1708932236]);
        $expected = new DateTime();
        $expected->setTimestamp(1708932236);

        $this->assertEquals(
            $expected,
            $doc->changed,
            'unexpected changed',
        );
    }

    public function testEnrichGenerated(): void
    {
        $doc = $this->enrichWithData(['generated' => 1708932236]);
        $expected = new DateTime();
        $expected->setTimestamp(1708932236);

        $this->assertEquals(
            $expected,
            $doc->generated,
            'unexpected generated',
        );
    }

    public function testEnrichDate(): void
    {
        $doc = $this->enrichWithData(['base' => ['date' => 1708932236]]);
        $expected = new DateTime();
        $expected->setTimestamp(1708932236);

        $this->assertEquals(
            $expected,
            $doc->date,
            'unexpected generated',
        );
    }

    public function testEnrichArchive(): void
    {
        $doc = $this->enrichWithData(['base' => ['archive' => true]]);
        $this->assertTrue(
            $doc->archived,
            'unexpected language',
        );
    }

    public function testEnrichSpTitle(): void
    {
        $doc = $this->enrichWithData(['metadata' => ['headline' => 'test']]);
        $this->assertEquals(
            'test',
            $doc->headline,
            'unexpected title',
        );
    }

    public function testEnrichSpTitleWithTeaserHeadlineFallback(): void
    {
        $doc = $this->enrichWithData(['base' => [
            'title' => 'test',
            'teaser' => [
                'headline' => 'test',
            ],
        ]]);
        $this->assertEquals(
            'test',
            $doc->headline,
            'unexpected title',
        );
    }

    public function testEnrichSpTitleWithTeaserTitleFallback(): void
    {
        $doc = $this->enrichWithData(['base' => ['title' => 'test']]);
        $this->assertEquals(
            'test',
            $doc->headline,
            'unexpected title',
        );
    }

    public function testEnrichKeywords(): void
    {
        $doc = $this->enrichWithData(['metadata' => [
            'keywords' => ['abc', 'cde'],
        ]]);
        $this->assertEquals(
            ['abc', 'cde'],
            $doc->keywords,
            'unexpected keywords',
        );
    }

    public function testEnrichSpSites(): void
    {
        $doc = $this->enrichWithData(['base' => [
            'trees' => [
                'navigation' => [
                    'parents' => [
                        ['siteGroup' => ['id' => '123']],
                        ['siteGroup' => ['id' => '456']],
                    ],
                ],
            ],
        ]]);
        $this->assertEquals(
            ['123', '456', '999'],
            $doc->sites,
            'unexpected keywords',
        );
    }

    public function testEnrichSpSitesWithInvalidRootResource(): void
    {
        $resource = $this->createResource([
            'url' => 'throwException',
        ]);

        $this->expectException(DocumentEnrichingException::class);
        $this->enrichWithResource($resource);
    }

    public function testEnrichCategories(): void
    {
        $doc = $this->enrichWithData(['metadata' => [
            'categories' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
        ]]);
        $this->assertEquals(
            ['1', '2', '3'],
            $doc->categories,
            'unexpected category',
        );
    }

    public function testEnrichCategoryPath(): void
    {
        $doc = $this->enrichWithData(['metadata' => [
            'categoriesPath' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
        ]]);
        $this->assertEquals(
            ['1', '2', '3'],
            $doc->category_path,
            'unexpected category_path',
        );
    }

    public function testEnrichSpGroup(): void
    {
        $doc = $this->enrichWithData([
            'groupPath' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
        ]);
        $this->assertEquals(
            2,
            $doc->group,
            'unexpected group',
        );
    }

    public function testEnrichSpGroupPath(): void
    {
        $doc = $this->enrichWithData([
            'groupPath' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
        ]);
        $this->assertEquals(
            [1, 2, 3],
            $doc->group_path,
            'unexpected group_path',
        );
    }

    public function testEnrichExpiredDateViaScheduling(): void
    {
        $doc = $this->enrichWithData([
            'base' => ['date' => 1707549836],
            'metadata' => [
                'scheduling' => [
                    ['from' => 1708932236, 'contentType' => 'test'],
                    ['from' => 1709105036, 'contentType' => 'test'],
                ],
            ],
        ]);

        $expected = new DateTime();
        $expected->setTimestamp(1707549836);

        $this->assertEquals(
            $expected,
            $doc->date,
            'unexpected date',
        );
    }

    public function testEnrichValidDateViaScheduling(): void
    {
        $date = new DateTime();
        $date = $date->add(new \DateInterval('P1D'));

        $doc = $this->enrichWithData([
            'base' => ['date' => 1707549836],
            'metadata' => [
                'scheduling' => [
                    ['from' => 1708932236, 'contentType' => 'test'],
                    ['from' => $date->getTimestamp(), 'contentType' => 'test'],
                ],
            ],
        ]);

        $expected = new DateTime();
        $expected->setTimestamp($date->getTimestamp());

        $this->assertEquals(
            $expected,
            $doc->date,
            'unexpected date',
        );
    }

    public function testEnrichContentTypeViaScheduling(): void
    {
        $doc = $this->enrichWithData([
            'objectType' => 'content',
            'base' => ['date' => 1707549836],
            'metadata' => [
                'scheduling' => [
                    ['from' => 1708932236, 'contentType' => 'test1'],
                    ['from' => 1709105036, 'contentType' => 'test2'],
                    ['from' => 1707981836, 'contentType' => 'test1'],
                ],
            ],
        ]);

        $this->assertEquals(
            ['content', 'article', 'test1', 'test2'],
            $doc->content_types,
            'unexpected contenttype',
        );
    }

    public function testEnrichDateListViaScheduling(): void
    {
        $dateA = new Datetime();
        //reset microseconds
        $dateA->setTime(12, 0, 0, 0);
        // cause DateInterval doesn't consider microseconds
        $dateA->add(new \DateInterval('P1D'));

        $dateB = new Datetime();
        $dateB->setTime(12, 0, 0, 0);
        $dateB->add(new \DateInterval('P3D'));

        $doc = $this->enrichWithData([
            'metadata' => [
                'scheduling' => [
                    ['from' => $dateA->getTimestamp(), 'contentType' => 'test'],
                    ['from' => $dateB->getTimestamp(), 'contentType' => 'test'],
                ],
            ],
        ]);
        $this->assertEquals(
            [$dateA, $dateB],
            $doc->date_list,
            'unexpected date list',
        );
        $this->assertEquals(
            $dateA,
            $doc->valid_from,
            'valid_from should be the first upcoming date',
        );
        $this->assertEquals(
            $dateB,
            $doc->valid_until,
            'valid_until should be the last date of the scheduling',
        );
    }

    public function testEnrichDateListViaSchedulingDependOnCurrentDate(): void
    {
        $pastDate = new DateTime();
        $pastDate->setTime(12, 0, 0, 0);
        $pastDate->sub(new \DateInterval('P7D'));

        $nextDate = new DateTime();
        $nextDate->setTime(12, 0, 0, 0);
        $nextDate->add(new \DateInterval('P7D'));

        $afterNextDate = new DateTime();
        $afterNextDate->setTime(12, 0, 0, 0);
        $afterNextDate->add(new \DateInterval('P14D'));

        $doc = $this->enrichWithData([
            'metadata' => [
                'scheduling' => [
                    ['from' => $pastDate->getTimestamp(), 'contentType' => 'schedule_start'],
                    ['from' => $nextDate->getTimestamp(), 'contentType' => 'schedule_start'],
                    ['from' => $afterNextDate->getTimestamp(), 'contentType' => 'schedule_start'],
                ],
            ],
        ]);

        $this->assertEquals(
            [$nextDate, $afterNextDate],
            $doc->date_list,
            'past scheduling entries should be left out',
        );
    }

    public function testEnrichDefaultMetaContentType(): void
    {
        $doc = $this->enrichWithData([]);

        $this->assertEquals(
            'text/html; charset=UTF-8',
            $doc->content_type,
            'unexpected content type',
        );
    }

    public function testEnrichIncludeGroups(): void
    {
        $doc = $this->enrichWithData([
            'access' => [
                'type' => 'allow',
                'groups' => ['100010100000001028'],
            ],
        ]);

        $this->assertEquals(
            ['1028', 'admin'],
            $doc->include_groups,
            'unexpected include_groups',
        );
    }

    public function testEnrichIncludeAllGroups(): void
    {
        $doc = $this->enrichWithData([]);

        $this->assertEquals(
            ['all'],
            $doc->include_groups,
            'unexpected include_groups',
        );
    }

    public function testEnrichExcludeGroups(): void
    {
        $doc = $this->enrichWithData([
            'access' => [
                'type' => 'deny',
                'groups' => ['100010100000001028'],
            ],
        ]);

        $this->assertEquals(
            ['1028'],
            $doc->exclude_groups,
            'unexpected exclude_groups',
        );
    }

    public function testEnrichNonExcludeGroups(): void
    {
        $doc = $this->enrichWithData([]);

        $this->assertEquals(
            ['none'],
            $doc->exclude_groups,
            'unexpected exclude_groups',
        );
    }

    public function testEnrichMetaContentType(): void
    {
        $doc = $this->enrichWithData([
            'base' => ['mime' => 'application/pdf'],
        ]);

        $this->assertEquals(
            'application/pdf',
            $doc->content_type,
            'unexpected content type',
        );
    }

    public function testEnrichSource(): void
    {
        $doc = $this->enrichWithData([]);

        $this->assertEquals(
            'genai',
            $doc->source,
            'unexpected source',
        );
    }

    public function testEnrichLocale(): void
    {
        $doc = $this->enrichWithData([
            'locale' => 'it_IT',
        ]);

        $this->assertEquals(
            'it_IT',
            $doc->locale,
            'the full locale should be kept next to the language',
        );
    }

    public function testEnrichCategoryNames(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'categories' => [
                    ['id' => 10, 'name' => 'Category A'],
                    ['id' => 20, 'name' => 'Category B'],
                ],
            ],
        ]);

        $this->assertEquals(
            ['Category A', 'Category B'],
            $doc->category_names,
            'unexpected category names',
        );
    }

    public function testEnrichContent(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'categories' => [
                    ['id' => 1, 'name' => 'CategoryA'],
                    ['id' => 2, 'name' => 'CategoryB'],
                ],
            ],
            'searchindexdata' => ['content' => 'abc'],
        ]);

        $this->assertEquals(
            'abc collected content CategoryA CategoryB',
            $doc->content,
            'unexpected content',
        );
    }

    public function testEnrichContentUsesCategoryTitleFromResource(): void
    {
        $categoryResource = $this->createResource([
            'base' => ['title' => 'Category Title'],
        ]);
        $this->navigationLoader
            ->method('load')
            ->willReturn($categoryResource);

        $doc = $this->enrichWithData([
            'metadata' => [
                'categories' => [
                    ['id' => 1, 'name' => 'CategoryName', 'url' => '/category.php'],
                ],
            ],
            'searchindexdata' => ['content' => 'abc'],
        ]);

        $this->assertStringContainsString(
            'Category Title',
            $doc->content,
            'expected category base.title in content',
        );
        $this->assertStringNotContainsString(
            'CategoryName',
            $doc->content,
            'expected category name to be replaced by base.title',
        );
    }

    public function testEnrichContentFallsBackToCategoryNameOnLoadFailure(): void
    {
        $this->navigationLoader
            ->method('load')
            ->willThrowException(new \RuntimeException('not found'));

        $doc = $this->enrichWithData([
            'metadata' => [
                'categories' => [
                    ['id' => 1, 'name' => 'CategoryName', 'url' => '/missing.php'],
                ],
            ],
        ]);

        $this->assertStringContainsString(
            'CategoryName',
            $doc->content,
            'expected fallback to category name on load failure',
        );
    }

    public function testEnrichContentCachesDuplicateCategoryUrls(): void
    {
        $categoryResource = $this->createResource([
            'base' => ['title' => 'Cached Title'],
        ]);
        $this->navigationLoader
            ->expects($this->once())
            ->method('load')
            ->willReturn($categoryResource);

        $this->enrichWithData([
            'metadata' => [
                'categories' => [
                    ['id' => 1, 'name' => 'A', 'url' => '/cat.php'],
                    ['id' => 2, 'name' => 'B', 'url' => '/cat.php'],
                ],
            ],
        ]);
    }

    public function testCleanupResetsCategoryTitleCache(): void
    {
        $categoryResource = $this->createResource([
            'base' => ['title' => 'Title'],
        ]);
        $this->navigationLoader
            ->expects($this->exactly(2))
            ->method('load')
            ->willReturn($categoryResource);

        $data = [
            'metadata' => [
                'categories' => [
                    ['id' => 1, 'name' => 'A', 'url' => '/cat.php'],
                ],
            ],
        ];
        $this->enrichWithData($data);
        $this->enricher->cleanup();
        $this->enrichWithData($data);
    }

    public function testEnrichContactPointContent(): void
    {
        $doc = $this->enrichWithData([
            'metadata' => [
                'contactPoint' => [
                    'contactData' => [
                        'phoneList' => [
                            ['phone' => [
                                'countryCode' => '49',
                                'areaCode' => '251',
                                'localNumber' => '123',
                            ]],
                            ['phone' => [
                                'countryCode' => '49',
                                'areaCode' => '2571',
                                'localNumber' => '456',
                            ]],
                        ],
                        'emailList' => [
                            ['email' => 'test1@sitepark.com'],
                            ['email' => 'test2@sitepark.com'],
                        ],
                    ],
                    'addressData' => [
                        'street' => 'Neubrückenstr',
                        'buildingName' => 'Pressehaus',
                        'postOfficeBoxData' => [
                            'buildingName' => 'Sitepark',
                        ],
                        'notice' => 'Hinweise zur Adresse',
                        'publicTransportationNotice' => 'ÖPNV Infos',
                        'accessibleDescription' => 'Barrierefreier Zugang',
                    ],
                ],
            ],
        ]);

        $this->assertEquals(
            'collected content +49 251 0251 123 +49 2571 02571 456 '
            . 'test1@sitepark.com test2@sitepark.com '
            . 'Neubrückenstr Pressehaus Sitepark '
            . 'Hinweise zur Adresse ÖPNV Infos Barrierefreier Zugang',
            $doc->content,
            'unexpected content',
        );
    }

    public function testEnrichSearchTip(): void
    {
        $doc = $this->enrichWithData([
            'objectType' => 'searchTip',
            'name' => 'Sample Tip',
            'base' => [
                'title' => 'Abc',
            ],
            'metadata' => [
                'keywords' => ['testKeyword'],
                'categories' => [
                    [
                        'id' => 1234,
                        'title' => 'a',
                    ],
                    [
                        'id' => 5678,
                        'title' => 'b',
                    ],
                ],
            ],
            'groupPath' => [
                [
                    'id' => 1002,
                    'groupType' => 'rootGroup',
                ],
                [
                    'id' => 1006,
                    'groupType' => 'commonGroup',
                ],
            ],
            'searchindexdata.content' => 'search content',
        ]);
        $this->assertEquals('testKeyword', $doc->keywords[0]);
        $this->assertEquals(2, count($doc->categories));
        $this->assertEquals(2, count($doc->group_path));
        $this->assertNull(
            $doc->headline,
            'searchTip should not have a headline',
        );
        $this->assertNull(
            $doc->content,
            'searchTip should not have content',
        );
    }

    private function enrichWithResource(
        Resource $resource,
    ): GenAiDocument {
        /** @var GenAiDocument $doc */
        $doc = $this->enricher->enrichDocument(
            $resource,
            new GenAiDocument(),
            'progress-id',
        );
        return $doc;
    }

    /**
     * @param array<string, array<string,mixed>|string> $data
     */
    private function enrichWithData(
        array $data,
    ): GenAiDocument {
        $resource = $this->createResource($data);
        /** @var GenAiDocument $doc */
        $doc = $this->enricher->enrichDocument(
            $resource,
            new GenAiDocument(),
            'progress-id',
        );
        return $doc;
    }

    /**
     * @param array<string, mixed>> $data
     */
    private function createResource(array $data): Resource
    {
        return new Resource(
            $data['url'] ?? '',
            $data['id'] ?? '123',
            $data['name'] ?? '',
            $data['objectType'] ?? '',
            ResourceLanguage::of($data['locale'] ?? ''),
            new DataBag($data),
        );
    }

    public function testEnrichValidUntilFromSchedulingEnd(): void
    {
        $from = new DateTime();
        $from->setTime(12, 0, 0, 0);
        $from->add(new \DateInterval('P1D'));

        $to = new DateTime();
        $to->setTime(12, 0, 0, 0);
        $to->add(new \DateInterval('P5D'));

        $doc = $this->enrichWithData([
            'metadata' => [
                'scheduling' => [
                    [
                        'from' => $from->getTimestamp(),
                        'to' => $to->getTimestamp(),
                        'contentType' => 'event',
                    ],
                ],
            ],
        ]);

        $this->assertEquals($from, $doc->valid_from, 'unexpected valid_from');
        $this->assertEquals(
            $to,
            $doc->valid_until,
            'valid_until should come from the scheduling end',
        );
    }
}
