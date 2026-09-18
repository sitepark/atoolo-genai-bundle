<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\SiteKit;

use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\Index\Exception\DocumentEnrichingException;
use Atoolo\Index\Service\Indexer\ContentCollector;
use Atoolo\Index\Service\Indexer\DocumentEnricher;
use Atoolo\Index\Service\Indexer\IndexDocument;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\Loader\SiteKitNavigationHierarchyLoader;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceLocation;
use DateTime;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Maps a SiteKit resource onto the {@see GenAiDocument}.
 *
 * Derived from the schema 2.x enricher of the search-bundle. The field names
 * are semantic instead of Solr's `sp_*`, and everything that only serves
 * Solr's ranking or sorting - the boost keywords, the sort value, the start
 * letter, the geo points, the anchor and the canonical flag - is left out.
 *
 * @phpstan-type Phone array{
 *     countryCode?:string,
 *     areaCode?:string,
 *     localNumber?:string
 * }
 * @phpstan-type PhoneData array{phone:Phone}
 * @phpstan-type PhoneList array<PhoneData>
 * @phpstan-type Email array{email:string}
 * @phpstan-type EmailList array<Email>
 * @phpstan-type ContactData array{
 *     phoneList?:PhoneList,
 *     emailList:EmailList
 * }
 * @phpstan-type AddressData array{
 *     buildingName?:string,
 *     street?:string,
 *     postOfficeBoxData?: array{
 *          buildingName?:string
 *     },
 *     notice?:string,
 *     publicTransportationNotice?:string,
 *     accessibleDescription?:string
 * }
 * @phpstan-type ContactPoint array{
 *     contactData?:ContactData,
 *     addressData?:AddressData
 * }
 * @implements DocumentEnricher<GenAiDocument>
 */
class DefaultGenAiDocumentEnricher implements
    DocumentEnricher,
    LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var array<string,string> */
    private array $categoryTitleCache = [];

    public function __construct(
        private readonly SiteKitNavigationHierarchyLoader $navigationLoader,
        private readonly ContentCollector $contentCollector,
        private readonly string $source = 'genai',
    ) {}

    public function cleanup(): void
    {
        $this->categoryTitleCache = [];
        $this->navigationLoader->cleanup();
    }

    /**
     * @throws DocumentEnrichingException
     */
    public function enrichDocument(
        Resource $resource,
        IndexDocument $doc,
        string $processId,
    ): IndexDocument {
        $doc->process_id = $processId;

        $this->enrichCommonFields($resource, $doc);
        $this->enrichCategoryFields($resource, $doc);
        $this->enrichGroupFields($resource, $doc);

        if ($doc->object_type === 'searchTip') {
            return $doc;
        }

        $this->enrichCommonTextFields($resource, $doc);
        $this->enrichDateFields($resource, $doc);
        $this->enrichAccessFields($resource, $doc);
        $this->enrichContent($resource, $doc);

        return $doc;
    }

    private function enrichCommonFields(
        Resource $resource,
        GenAiDocument $doc,
    ): void {
        $data = $resource->data;
        $base = new DataBag($data->getAssociativeArray('base'));
        $metadata = new DataBag($data->getAssociativeArray('metadata'));

        $doc->id = $resource->id;
        $doc->source = $this->source;
        $doc->object_type = $resource->objectType;

        $doc->url = $data->getString('mediaUrl')
            ?: $data->getString('url');

        /** @var string[] $keywords */
        $keywords = $metadata->getArray('keywords');
        if (!empty($keywords)) {
            $doc->keywords = $keywords;
        }

        $doc->changed = $this->toDateTime($data->getInt('changed'));
        $doc->generated = $this->toDateTime($data->getInt('generated'));

        $locale = $this->getLocaleFromResource($resource);
        $doc->locale = $locale;
        $doc->language = $this->toLangFromLocale($locale);
        $doc->archived = $base->getBool('archive');

        $doc->content_types = [$resource->objectType];
        if ($data->getBool('media') !== true) {
            $doc->content_types[] = 'article';
        }

        $doc->content_type = $base->getString(
            'mime',
            'text/html; charset=UTF-8',
        );
    }

    private function enrichCategoryFields(
        Resource $resource,
        GenAiDocument $doc,
    ): void {
        $metadata = new DataBag(
            $resource->data->getAssociativeArray('metadata'),
        );

        /** @var array<array{id:int,name?:string,url?:string}> $categoryList */
        $categoryList = $metadata->getArray('categories');
        if (!empty($categoryList)) {
            $categoryIdList = [];
            $categoryNameList = [];
            foreach ($categoryList as $category) {
                $categoryIdList[] = (string) $category['id'];
                $name = $this->categoryName($category, $resource->lang);
                if ($name !== '') {
                    $categoryNameList[] = $name;
                }
            }
            $doc->categories = $categoryIdList;
            if (!empty($categoryNameList)) {
                $doc->category_names = $categoryNameList;
            }
        }

        /** @var array<array{id:int}> $categoryPath */
        $categoryPath = $metadata->getArray('categoriesPath');
        if (!empty($categoryPath)) {
            $categoryIdPath = [];
            foreach ($categoryPath as $category) {
                $categoryIdPath[] = (string) $category['id'];
            }
            $doc->category_path = $categoryIdPath;
        }
    }

    private function enrichGroupFields(
        Resource $resource,
        GenAiDocument $doc,
    ): void {
        /** @var array<array{id:int}> $groupPath */
        $groupPath = $resource->data->getArray('groupPath');
        $groupPathAsIdList = [];
        foreach ($groupPath as $group) {
            $groupPathAsIdList[] = $group['id'];
        }
        if (count($groupPathAsIdList) > 2) {
            $doc->group = $groupPathAsIdList[count($groupPathAsIdList) - 2];
        }
        $doc->group_path = $groupPathAsIdList;

        try {
            $sites = $this->getParentSiteGroupIdList($resource);
            $navigationRoot = $this->navigationLoader->loadRoot(
                $resource->toLocation(),
            );
            $siteGroupId = $navigationRoot->data->getInt('siteGroup.id');
            if ($siteGroupId !== 0) {
                $sites[] = (string) $siteGroupId;
            }
            $doc->sites = array_values(array_unique($sites));
        } catch (Exception $e) {
            throw new DocumentEnrichingException(
                $resource->toLocation(),
                'Unable to set sites: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function enrichCommonTextFields(
        Resource $resource,
        GenAiDocument $doc,
    ): void {
        $data = $resource->data;
        $base = new DataBag($data->getAssociativeArray('base'));
        $metadata = new DataBag($data->getAssociativeArray('metadata'));

        $doc->title = $base->getString('title');
        $doc->description = $metadata->getString(
            'intro',
            $metadata->getString('description'),
        );

        /** @var string[] $contentTypes */
        $contentTypes = $data->getArray('contentSectionTypes');
        if ($base->has('teaser.image')) {
            $contentTypes[] = 'teaserImage';
        }
        if ($base->has('teaser.image.copyright')) {
            $contentTypes[] = 'teaserImageCopyright';
        }
        if ($base->has('teaser.headline')) {
            $contentTypes[] = 'teaserHeadline';
        }
        if ($base->has('teaser.text')) {
            $contentTypes[] = 'teaserText';
        }
        $doc->content_types = array_merge(
            $doc->content_types ?? [],
            $contentTypes,
        );

        $doc->headline = $base->getString('teaser.headline')
            ?: $metadata->getString('headline')
            ?: $base->getString('title');
    }

    private function enrichDateFields(
        Resource $resource,
        GenAiDocument $doc,
    ): void {
        $base = new DataBag($resource->data->getAssociativeArray('base'));
        $metadata = new DataBag(
            $resource->data->getAssociativeArray('metadata'),
        );

        $doc->date = $this->toDateTime($base->getInt('date'));
        if ($doc->date !== null) {
            $doc->date_list = [$doc->date];
        }

        /**
         * @var array<array{from:int, to?:int, contentType:string}>
         *     $schedulingList
         */
        $schedulingList = $metadata->getArray('scheduling');
        if (empty($schedulingList)) {
            return;
        }

        $dateList = [];
        $contentTypeList = [];
        $validUntil = null;
        $currentDay = (new DateTime())->format('Ymd');

        foreach ($schedulingList as $scheduling) {
            $contentTypeList[] = explode(' ', $scheduling['contentType']);
            $from = $this->toDateTime($scheduling['from']);
            if ($from === null || $from->format('Ymd') < $currentDay) {
                continue;
            }
            $dateList[] = $from;
            $to = isset($scheduling['to'])
                ? $this->toDateTime($scheduling['to'])
                : $from;
            if ($to !== null && ($validUntil === null || $to > $validUntil)) {
                $validUntil = $to;
            }
        }

        $doc->content_types = array_values(array_unique(array_merge(
            $doc->content_types ?? [],
            ...$contentTypeList,
        )));

        if (empty($dateList)) {
            return;
        }

        $doc->date = $dateList[0];
        $doc->date_list = $dateList;
        $doc->valid_from = $dateList[0];
        $doc->valid_until = $validUntil;
    }

    private function enrichAccessFields(
        Resource $resource,
        GenAiDocument $doc,
    ): void {
        /** @var string[] $groups */
        $groups = $resource->data->getArray('access.groups');
        $accessType = $resource->data->getString('access.type');

        if ($accessType === 'allow' && !empty($groups)) {
            $doc->include_groups = array_map(
                fn($id): string => (string) $this->idWithoutSignature($id),
                $groups,
            );
            $doc->include_groups[] = 'admin';
        } elseif ($accessType === 'deny' && !empty($groups)) {
            $doc->exclude_groups = array_map(
                fn($id): string => (string) $this->idWithoutSignature($id),
                $groups,
            );
        } else {
            $doc->exclude_groups = ['none'];
            $doc->include_groups = ['all'];
        }
    }

    private function enrichContent(
        Resource $resource,
        GenAiDocument $doc,
    ): void {
        $content = [];
        $content[] = $resource->data->getString('searchindexdata.content');
        $content[] = $this->contentCollector->collect(
            $resource->data->getArray('content'),
            $resource,
        );

        /** @var ContactPoint $contactPoint */
        $contactPoint = $resource->data->getArray('metadata.contactPoint');
        $content[] = $this->contactPointToContent($contactPoint);

        /** @var array<array{id?:int,name?:string,url?:string}> $categories */
        $categories = $resource->data->getArray('metadata.categories');
        foreach ($categories as $category) {
            $content[] = $this->categoryName($category, $resource->lang);
        }

        $cleanContent = preg_replace('/\s+/', ' ', implode(' ', $content));
        $doc->content = trim($cleanContent ?? '');
    }

    /**
     * @param array{id?:int,name?:string,url?:string} $category
     */
    private function categoryName(
        array $category,
        ResourceLanguage $lang,
    ): string {
        $url = $category['url'] ?? null;
        $title = $url !== null ? $this->loadCategoryTitle($url, $lang) : '';
        return !empty($title) ? $title : ($category['name'] ?? '');
    }

    private function loadCategoryTitle(
        string $url,
        ResourceLanguage $lang,
    ): string {
        $cacheKey = $url . ':' . $lang->code;
        if (array_key_exists($cacheKey, $this->categoryTitleCache)) {
            return $this->categoryTitleCache[$cacheKey];
        }

        $title = '';
        try {
            $categoryResource = $this->navigationLoader->load(
                ResourceLocation::of($url, $lang),
            );
            $title = $categoryResource->data->getString('base.title', '');
        } catch (\Throwable $th) {
            $this->logger?->error(
                sprintf('unable to load category with url "%s"', $url),
                [
                    'error' => $th,
                    'url' => $url,
                ],
            );
        }

        $this->categoryTitleCache[$cacheKey] = $title;
        return $title;
    }

    /**
     * @param ContactPoint $contactPoint
     */
    private function contactPointToContent(array $contactPoint): string
    {
        if (empty($contactPoint)) {
            return '';
        }

        $content = [];
        foreach (($contactPoint['contactData']['phoneList'] ?? []) as $phone) {
            $countryCode = $phone['phone']['countryCode'] ?? '';
            if (
                !empty($countryCode)
                && !in_array($countryCode, $content, true)
            ) {
                $content[] = '+' . $countryCode;
            }
            $areaCode = $phone['phone']['areaCode'] ?? '';
            if (!empty($areaCode) && !in_array($areaCode, $content, true)) {
                $content[] = $areaCode;
                $content[] = '0' . $areaCode;
            }
            $content[] = $phone['phone']['localNumber'] ?? '';
        }
        foreach ($contactPoint['contactData']['emailList'] ?? [] as $email) {
            $content[] = $email['email'];
        }

        if (isset($contactPoint['addressData'])) {
            $data = $contactPoint['addressData'];
            $content[] = $data['street'] ?? '';
            $content[] = $data['buildingName'] ?? '';
            $content[] = $data['postOfficeBoxData']['buildingName'] ?? '';
            $content[] = $data['notice'] ?? '';
            $content[] = $data['publicTransportationNotice'] ?? '';
            $content[] = $data['accessibleDescription'] ?? '';
        }

        return implode(' ', $content);
    }

    private function idWithoutSignature(string $id): int
    {
        return (int) substr($id, -11);
    }

    private function getLocaleFromResource(Resource $resource): string
    {
        $locale = $resource->data->getString('locale');
        if ($locale !== '') {
            return $locale;
        }

        /** @var array<array{locale: ?string}> $groupPath */
        $groupPath = $resource->data->getArray('groupPath');
        if (!empty($groupPath)) {
            $len = count($groupPath);
            for ($i = $len - 1; $i >= 0; $i--) {
                $group = $groupPath[$i];
                if (isset($group['locale'])) {
                    return $group['locale'];
                }
            }
        }

        return 'de_DE';
    }

    private function toLangFromLocale(string $locale): string
    {
        if (str_contains($locale, '_')) {
            $parts = explode('_', $locale);
            return $parts[0];
        }
        return $locale;
    }

    private function toDateTime(int $timestamp): ?DateTime
    {
        if ($timestamp <= 0) {
            return null;
        }

        $dateTime = new DateTime();
        $dateTime->setTimestamp($timestamp);
        return $dateTime;
    }

    /**
     * @return string[]
     */
    private function getParentSiteGroupIdList(Resource $resource): array
    {
        /** @var array<array{siteGroup: array{id: ?string}}> $parents */
        $parents = $resource->data->getAssociativeArray(
            'base.trees.navigation.parents',
        );
        if (empty($parents)) {
            return [];
        }

        $siteGroupIdList = [];
        foreach ($parents as $parent) {
            if (isset($parent['siteGroup']['id'])) {
                $siteGroupIdList[] = $parent['siteGroup']['id'];
            }
        }

        return $siteGroupIdList;
    }
}
