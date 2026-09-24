<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\SiteKit;

use Atoolo\GenAi\Dto\Indexer\Category;
use Atoolo\GenAi\Dto\Indexer\ContentSection;
use Atoolo\GenAi\Dto\Indexer\Link;
use Atoolo\GenAi\Dto\Indexer\LinkSection;
use Atoolo\GenAi\Dto\Indexer\TextSection;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\Index\Service\Indexer\DocumentEnricher;
use Atoolo\Index\Service\Indexer\IndexDocument;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\Loader\SiteKitNavigationHierarchyLoader;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceLocation;
use DateTime;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Maps a SiteKit resource onto a {@see GenAiDocument}.
 *
 * Unlike the Solr enricher this one does not flatten the resource into a
 * single string of text. The GenAI application chunks along the structure it
 * is given, so the enricher keeps it: one section per content block, with the
 * rich text as the HTML fragment the editor wrote. Whatever only served
 * Solr's ranking, sorting or access filtering has no place here - the
 * application knows none of it.
 *
 * @phpstan-type Phone array{
 *     type?:string,
 *     nationalNumber?:string,
 *     internationalNumber?:string,
 *     countryCode?:string,
 *     areaCode?:string,
 *     localNumber?:string,
 *     extension?:string
 * }
 * @phpstan-type PhoneData array{phone?:Phone}
 * @phpstan-type Email array{email?:string}
 * @phpstan-type ContactData array{
 *     phoneList?:array<PhoneData>,
 *     emailList?:array<Email>,
 *     room?:string
 * }
 * @phpstan-type AddressData array{
 *     buildingName?:string,
 *     street?:string,
 *     housenumber?:string,
 *     postalCode?:string,
 *     city?:string,
 *     postOfficeBoxData?:array{buildingName?:string},
 *     notice?:string,
 *     publicTransportationNotice?:string,
 *     accessibleDescription?:string
 * }
 * @phpstan-type ContactPoint array{
 *     headline?:string,
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
        private readonly ResourceChannel $resourceChannel,
        private readonly string $source = 'genai',
    ) {}

    public function cleanup(): void
    {
        $this->categoryTitleCache = [];
        $this->navigationLoader->cleanup();
    }

    public function enrichDocument(
        Resource $resource,
        IndexDocument $doc,
        string $processId,
    ): IndexDocument {
        $doc->type = $resource->data->getBool('media')
            ? GenAiDocument::TYPE_MEDIA
            : GenAiDocument::TYPE_ARTICLE;
        $doc->processId = $processId;

        $this->enrichCommonFields($resource, $doc);
        $this->enrichCategories($resource, $doc);

        if ($doc->objectType === 'searchTip') {
            return $doc;
        }

        if ($doc->isMedia()) {
            $this->enrichMedia($resource, $doc);
        } else {
            $this->enrichArticle($resource, $doc);
        }

        return $doc;
    }

    private function enrichCommonFields(
        Resource $resource,
        GenAiDocument $document,
    ): void {
        $data = $resource->data;
        $base = new DataBag($data->getAssociativeArray('base'));

        $document->id = $resource->id;
        $document->source = $this->source;
        $document->objectType = $resource->objectType;
        $document->title = $base->getString('title');
        $document->url = $this->toAbsoluteUrl(
            $data->getString('mediaUrl') ?: $data->getString('url'),
        );
        $document->date = $this->toDateTime($base->getInt('date'));

        $this->enrichKeywords($resource, $document);
    }

    /**
     * The keywords of the editors and the terms they want the resource to be
     * found by. The Solr index boosts the latter; to a semantic search both
     * are words the text may not contain.
     */
    private function enrichKeywords(
        Resource $resource,
        GenAiDocument $document,
    ): void {
        $metadata = new DataBag(
            $resource->data->getAssociativeArray('metadata'),
        );
        foreach (['keywords', 'boostKeywords'] as $name) {
            $document->addKeywords(
                ...array_values(
                    array_filter($metadata->getArray($name), 'is_string'),
                ),
            );
        }
    }

    /**
     * The GenAI application links the sources of an answer, so the url of a
     * document has to be one that can be followed from anywhere. The CMS
     * knows the resource by its path alone; scheme and host are those of the
     * channel the indexer runs for. A url that already names a host - a
     * medium served by another server, an external target - is kept as it is.
     */
    private function toAbsoluteUrl(string $url): string
    {
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*://|^//#i', $url)) {
            return $url;
        }

        return 'https://' . $this->resourceChannel->serverName
            . (str_starts_with($url, '/') ? '' : '/') . $url;
    }

    /**
     * The categories of the resource, followed by the ids of their ancestors.
     *
     * SiteKit keeps the ancestors in `categoriesPath` as a plain set of ids -
     * which id belongs to which category is not recorded - so the tree the
     * GenAI application allows cannot be rebuilt. The ancestors are sent as
     * categories of their own instead: the application skips nameless ones
     * when it renders the context of a chunk, but keeps their ids, and that
     * is what a filter on a parent category needs.
     */
    private function enrichCategories(
        Resource $resource,
        GenAiDocument $document,
    ): void {
        $metadata = new DataBag(
            $resource->data->getAssociativeArray('metadata'),
        );

        $categories = [];

        /** @var array<array{id?:int|string,name?:string,url?:string}> $list */
        $list = $metadata->getArray('categories');
        foreach ($list as $category) {
            $id = $this->categoryId($category);
            if ($id === '' || isset($categories[$id])) {
                continue;
            }
            $categories[$id] = new Category(
                $id,
                $this->categoryName($category, $resource->lang),
            );
        }

        /** @var array<array{id?:int|string,name?:string,url?:string}> $path */
        $path = $metadata->getArray('categoriesPath');
        foreach ($path as $category) {
            $id = $this->categoryId($category);
            if ($id === '' || isset($categories[$id])) {
                continue;
            }
            $categories[$id] = new Category(
                $id,
                $this->categoryName($category, $resource->lang),
            );
        }

        $document->categories = array_values($categories);
    }

    private function enrichArticle(
        Resource $resource,
        GenAiDocument $document,
    ): void {
        $base = new DataBag($resource->data->getAssociativeArray('base'));
        $metadata = new DataBag(
            $resource->data->getAssociativeArray('metadata'),
        );

        $document->headline = $base->getString('teaser.headline')
            ?: $metadata->getString('headline')
            ?: $base->getString('title');
        $document->kicker = $this->kicker($resource);
        $document->intro = $this->toParagraph(
            $metadata->getString('intro')
            ?: $metadata->getString('description'),
        );

        $sections = [];

        $indexData = $resource->data->getString('searchindexdata.content');
        if (trim($indexData) !== '') {
            $sections[] = new TextSection('', $indexData);
        }

        $this->collectSections(
            $resource->data->getArray('content'),
            $sections,
        );

        /** @var ContactPoint $contactPoint */
        $contactPoint = $resource->data->getArray('metadata.contactPoint');
        $contact = $this->toContactSection($contactPoint);
        if ($contact !== null) {
            $sections[] = $contact;
        }

        $document->content = $sections;
    }

    /**
     * The kicker of a resource is the one of its teaser or its own. Without
     * either, it is inherited from the nearest ancestor in the navigation
     * that has one, as the teasers of the website show it.
     */
    private function kicker(Resource $resource): ?string
    {
        $kicker = $this->nonEmpty(
            $resource->data->getString('base.teaser.kicker')
            ?: $resource->data->getString('base.kicker'),
        );
        if ($kicker !== null) {
            return $kicker;
        }

        try {
            $location = $resource->toLocation();
            while (
                ($parent = $this->navigationLoader->loadPrimaryParent($location))
                !== null
            ) {
                $kicker = $this->nonEmpty(
                    $parent->data->getString('base.kicker'),
                );
                if ($kicker !== null) {
                    return $kicker;
                }
                $location = $parent->toLocation();
            }
        } catch (\Throwable $th) {
            $this->logger?->error(
                sprintf(
                    'unable to inherit the kicker of "%s"',
                    $resource->location,
                ),
                [
                    'error' => $th,
                    'location' => $resource->location,
                ],
            );
        }

        return null;
    }

    /**
     * The application reads the intro as HTML, the CMS keeps it as plain
     * text, so it is escaped into a paragraph of its own.
     */
    private function toParagraph(string $text): ?string
    {
        $text = $this->nonEmpty($text);
        return $text === null ? null : '<p>' . $this->escape($text) . '</p>';
    }

    private function nonEmpty(string $text): ?string
    {
        $text = trim($text);
        return $text !== '' ? $text : null;
    }

    /**
     * The text of a binary asset has already been extracted by the CMS.
     */
    private function enrichMedia(
        Resource $resource,
        GenAiDocument $document,
    ): void {
        $rawText = trim(
            $resource->data->getString('searchindexdata.content'),
        );
        if ($rawText !== '') {
            $document->rawText = $rawText;
        }
    }

    /**
     * Walks the content tree and turns every block that carries text or
     * links into a section, in the order the editor arranged them. The walk
     * is recursive because a block may hold further blocks, as a multi column
     * layout does.
     *
     * @param array<mixed,mixed> $node
     * @param ContentSection[] $sections
     */
    private function collectSections(array $node, array &$sections): void
    {
        $model = $node['model'] ?? null;
        if (is_array($model)) {
            $section = $this->toSection($model);
            if ($section !== null) {
                $sections[] = $section;
            }
        }

        $items = $node['items'] ?? null;
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if (is_array($item)) {
                $this->collectSections($item, $sections);
            }
        }
    }

    /**
     * @param array<mixed,mixed> $model
     */
    private function toSection(array $model): ?ContentSection
    {
        $headline = is_string($model['headline'] ?? null)
            ? $model['headline']
            : '';

        $richText = $model['richText'] ?? null;
        if (
            is_array($richText)
            && ($richText['modelType'] ?? null) === 'html.richText'
            && is_string($richText['text'] ?? null)
            && trim($richText['text']) !== ''
        ) {
            return new TextSection($headline, $richText['text']);
        }

        if (($model['modelType'] ?? null) === 'content.quote') {
            $quote = $this->toBlockquote($model);
            if ($quote !== '') {
                return new TextSection($headline, $quote);
            }
        }

        if (($model['modelType'] ?? null) === 'content.linkList') {
            $links = $this->toLinks($model['items'] ?? null);
            if (!empty($links)) {
                return new LinkSection($headline, $links);
            }
        }

        return null;
    }

    /**
     * @param array<mixed,mixed> $model
     */
    private function toBlockquote(array $model): string
    {
        $quote = is_string($model['quote'] ?? null) ? $model['quote'] : '';
        if (trim($quote) === '') {
            return '';
        }

        $html = '<blockquote>' . $this->escape($quote);
        $citation = is_string($model['citation'] ?? null)
            ? $model['citation']
            : '';
        if (trim($citation) !== '') {
            $html .= '<cite>' . $this->escape($citation) . '</cite>';
        }
        return $html . '</blockquote>';
    }

    /**
     * The contact point of a resource - phone number, address, how to get
     * there - is not part of the content tree, although it answers the
     * questions people ask most. It becomes a section of its own so that it
     * is chunked and embedded with the rest.
     *
     * The facts go into one list, because a list is a single block to the
     * GenAI application and so is never torn apart by the chunking. The
     * notices follow as paragraphs of their own; they are prose and may well
     * end up in another chunk.
     *
     * @param ContactPoint $contactPoint
     */
    private function toContactSection(array $contactPoint): ?TextSection
    {
        $contactData = $contactPoint['contactData'] ?? [];
        $addressData = $contactPoint['addressData'] ?? [];

        $facts = [];
        foreach ($contactData['phoneList'] ?? [] as $entry) {
            $phone = $entry['phone'] ?? [];
            $number = $this->phoneNumber($phone);
            if ($number !== '') {
                $facts[] = $this->phoneLabel($phone) . ': ' . $number;
            }
        }
        foreach ($contactData['emailList'] ?? [] as $entry) {
            $email = trim($entry['email'] ?? '');
            if ($email !== '') {
                $facts[] = 'E-Mail: ' . $email;
            }
        }
        $address = $this->address($addressData);
        if ($address !== '') {
            $facts[] = 'Adresse: ' . $address;
        }
        $postOfficeBox = trim(
            $addressData['postOfficeBoxData']['buildingName'] ?? '',
        );
        if ($postOfficeBox !== '') {
            $facts[] = 'Postfach: ' . $postOfficeBox;
        }
        $room = trim($contactData['room'] ?? '');
        if ($room !== '') {
            $facts[] = 'Raum: ' . $room;
        }

        $notices = [];
        foreach (
            [
                'Hinweis' => $addressData['notice'] ?? '',
                'Anfahrt' => $addressData['publicTransportationNotice'] ?? '',
                'Barrierefreiheit'
                    => $addressData['accessibleDescription'] ?? '',
            ] as $label => $notice
        ) {
            $notice = trim($notice);
            if ($notice !== '') {
                $notices[] = $label . ': ' . $notice;
            }
        }

        if (empty($facts) && empty($notices)) {
            return null;
        }

        $html = '';
        if (!empty($facts)) {
            $html .= '<ul>';
            foreach ($facts as $fact) {
                $html .= '<li>' . $this->escape($fact) . '</li>';
            }
            $html .= '</ul>';
        }
        foreach ($notices as $notice) {
            $html .= '<p>' . $this->escape($notice) . '</p>';
        }

        $headline = trim($contactPoint['headline'] ?? '');
        return new TextSection(
            $headline !== '' ? $headline : 'Kontakt',
            $html,
        );
    }

    /**
     * SiteKit keeps the number in a readable form next to its parts, so the
     * parts are only assembled when it does not.
     *
     * @param Phone $phone
     */
    private function phoneNumber(array $phone): string
    {
        foreach (['nationalNumber', 'internationalNumber'] as $name) {
            $number = trim($phone[$name] ?? '');
            if ($number !== '') {
                return $this->withExtension($number, $phone);
            }
        }

        $countryCode = trim($phone['countryCode'] ?? '');
        $areaCode = trim($phone['areaCode'] ?? '');
        $localNumber = trim($phone['localNumber'] ?? '');
        if ($localNumber === '') {
            return '';
        }

        if ($countryCode !== '') {
            $number = '+' . $countryCode . ' ' . $areaCode;
        } elseif ($areaCode !== '') {
            $number = '0' . $areaCode;
        } else {
            $number = '';
        }

        return $this->withExtension(
            trim($number . ' ' . $localNumber),
            $phone,
        );
    }

    /**
     * @param Phone $phone
     */
    private function withExtension(string $number, array $phone): string
    {
        $extension = trim($phone['extension'] ?? '');
        return $extension === '' ? $number : $number . '-' . $extension;
    }

    /**
     * @param Phone $phone
     */
    private function phoneLabel(array $phone): string
    {
        return strtolower($phone['type'] ?? '') === 'fax'
            ? 'Fax'
            : 'Telefon';
    }

    /**
     * @param AddressData $addressData
     */
    private function address(array $addressData): string
    {
        $street = trim(
            trim($addressData['street'] ?? '')
            . ' ' . trim($addressData['housenumber'] ?? ''),
        );
        $city = trim(
            trim($addressData['postalCode'] ?? '')
            . ' ' . trim($addressData['city'] ?? ''),
        );

        $parts = [];
        foreach (
            [
                trim($addressData['buildingName'] ?? ''),
                $street,
                $city,
            ] as $part
        ) {
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode(', ', $parts);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return Link[]
     */
    private function toLinks(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $links = [];
        foreach ($items as $item) {
            if (
                !is_array($item)
                || ($item['modelType'] ?? null) !== 'content.link.link'
            ) {
                continue;
            }
            $url = is_string($item['url'] ?? null) ? $item['url'] : '';
            if ($url === '') {
                continue;
            }
            $label = is_string($item['label'] ?? null) ? $item['label'] : '';
            $links[] = new Link($url, strip_tags($label));
        }
        return $links;
    }

    /**
     * @param array{id?:int|string,name?:string,url?:string} $category
     */
    private function categoryId(array $category): string
    {
        $id = $category['id'] ?? null;
        if (is_int($id)) {
            return (string) $id;
        }
        return is_string($id) ? $id : '';
    }

    /**
     * @param array{id?:int|string,name?:string,url?:string} $category
     */
    private function categoryName(
        array $category,
        ResourceLanguage $lang,
    ): string {
        $url = $category['url'] ?? null;
        $title = $url !== null ? $this->loadCategoryTitle($url, $lang) : '';
        return $title !== '' ? $title : ($category['name'] ?? '');
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

    private function toDateTime(int $timestamp): ?DateTime
    {
        if ($timestamp <= 0) {
            return null;
        }

        $dateTime = new DateTime();
        $dateTime->setTimestamp($timestamp);
        return $dateTime;
    }
}
