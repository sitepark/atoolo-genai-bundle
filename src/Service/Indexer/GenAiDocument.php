<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\Index\Service\Indexer\IndexDocument;
use DateTimeInterface;

/**
 * The document that is sent to the GenAI application.
 *
 * The property names are the JSON keys of the remote API, so the mapping
 * lives in the property names and nowhere else. Fields that are not set are
 * left out of the payload.
 *
 * The port of the index-bundle only asks a document to represent itself as
 * data, which {@see jsonSerialize()} does. That this representation happens
 * to be a flat map of fields is this target's decision; {@see getFields()} is
 * the bundle's own API, used by {@see HttpIndexUpdater} to build a bulk.
 */
class GenAiDocument implements IndexDocument
{
    public ?string $id = null;
    public ?string $source = null;
    public ?string $process_id = null;
    public ?string $url = null;
    public ?string $title = null;
    public ?string $headline = null;
    public ?string $description = null;
    public ?string $language = null;
    public ?string $locale = null;
    public ?string $object_type = null;
    public ?string $content_type = null;
    /**
     * @var string[]|null
     */
    public ?array $content_types = null;
    /**
     * @var string[]|null
     */
    public ?array $keywords = null;
    /**
     * @var string[]|null
     */
    public ?array $categories = null;
    /**
     * @var string[]|null
     */
    public ?array $category_names = null;
    /**
     * @var string[]|null
     */
    public ?array $category_path = null;
    public ?int $group = null;
    /**
     * @var int[]|null
     */
    public ?array $group_path = null;
    /**
     * @var string[]|null
     */
    public ?array $sites = null;
    /**
     * @var string[]|null
     */
    public ?array $include_groups = null;
    /**
     * @var string[]|null
     */
    public ?array $exclude_groups = null;
    public ?bool $archived = null;
    public ?DateTimeInterface $changed = null;
    public ?DateTimeInterface $generated = null;
    public ?DateTimeInterface $date = null;
    /**
     * @var DateTimeInterface[]|null
     */
    public ?array $date_list = null;
    public ?DateTimeInterface $valid_from = null;
    public ?DateTimeInterface $valid_until = null;
    public ?string $content = null;
    public ?string $content_hash = null;

    /**
     * @var array<string,mixed>
     */
    private array $meta = [];

    public function setMeta(string $name, mixed $value): void
    {
        $this->meta[$name] = $value;
    }

    /**
     * @return array<string,mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * The hash lets the GenAI application skip documents whose indexed
     * content did not change, so that it does not embed them again. It is
     * derived from the fields the embedding is built from.
     */
    public function getContentHash(): string
    {
        return $this->content_hash ??= 'sha256:' . hash(
            'sha256',
            ($this->title ?? '') . "\n"
            . ($this->description ?? '') . "\n"
            . ($this->content ?? ''),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->getFields();
    }

    /**
     * @return array<string,mixed>
     */
    public function getFields(): array
    {
        $this->getContentHash();

        $fields = [];
        foreach (get_object_vars($this) as $name => $value) {
            if ($name === 'meta' || $value === null) {
                continue;
            }
            $fields[$name] = $this->toFieldValue($value);
        }

        if (!empty($this->meta)) {
            $fields['meta'] = $this->meta;
        }

        return $fields;
    }

    private function toFieldValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            return array_map(
                fn($item) => $this->toFieldValue($item),
                $value,
            );
        }
        return $value;
    }
}
