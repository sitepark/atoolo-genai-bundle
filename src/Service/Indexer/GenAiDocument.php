<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\GenAi\Dto\Indexer\Category;
use Atoolo\GenAi\Dto\Indexer\ContentSection;
use Atoolo\Index\Service\Indexer\IndexDocument;
use DateTimeInterface;

/**
 * The document that is sent to the GenAI application.
 *
 * The property names are the JSON keys of the remote API - camelCase, as the
 * application spells them - so the mapping lives in the property names and
 * nowhere else. Fields that are not set are left out of the payload.
 *
 * The application knows two kinds of document and selects them by `type`: an
 * article carries the sections it is made of, a medium the text that was
 * extracted from a binary asset. Both live in this one class, because the
 * enricher receives the document from the factory before it has seen the
 * resource and may only fill it in, never exchange it. {@see jsonSerialize()}
 * sends the fields of the kind the document was set to.
 */
class GenAiDocument implements IndexDocument
{
    public const TYPE_ARTICLE = 'article';
    public const TYPE_MEDIA = 'media';

    public string $type = self::TYPE_ARTICLE;
    public ?string $id = null;
    public ?string $source = null;
    public ?string $processId = null;
    public ?string $objectType = null;
    public ?string $title = null;
    public ?string $url = null;
    public ?DateTimeInterface $date = null;
    /**
     * @var Category[]
     */
    public array $categories = [];

    /**
     * Only sent for an article.
     */
    public ?string $kicker = null;
    /**
     * Only sent for an article.
     */
    public ?string $headline = null;
    /**
     * Only sent for an article.
     */
    public ?string $intro = null;
    /**
     * The sections of an article, in the order the editor arranged them.
     *
     * @var ContentSection[]
     */
    public array $content = [];

    /**
     * Only sent for a medium: the text the CMS extracted from the asset.
     */
    public ?string $rawText = null;

    public function isMedia(): bool
    {
        return $this->type === self::TYPE_MEDIA;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->type];

        foreach (
            [
                'id' => $this->id,
                'source' => $this->source,
                'processId' => $this->processId,
                'objectType' => $this->objectType,
                'title' => $this->title,
                'url' => $this->url,
            ] as $name => $value
        ) {
            if ($value !== null) {
                $data[$name] = $value;
            }
        }

        if ($this->date !== null) {
            $data['date'] = $this->date->format(DATE_ATOM);
        }

        if (!empty($this->categories)) {
            $data['categories'] = array_map(
                static fn(Category $category): array
                    => $category->jsonSerialize(),
                array_values($this->categories),
            );
        }

        if ($this->isMedia()) {
            if ($this->rawText !== null) {
                $data['rawText'] = $this->rawText;
            }
            return $data;
        }

        foreach (
            [
                'kicker' => $this->kicker,
                'headline' => $this->headline,
                'intro' => $this->intro,
            ] as $name => $value
        ) {
            if ($value !== null) {
                $data[$name] = $value;
            }
        }
        if (!empty($this->content)) {
            $data['content'] = array_map(
                static fn(ContentSection $section): array
                    => $section->jsonSerialize(),
                array_values($this->content),
            );
        }

        return $data;
    }
}
