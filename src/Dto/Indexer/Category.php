<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Indexer;

use JsonSerializable;

/**
 * A category a document is assigned to.
 *
 * Categories form a tree: every category but a root one refers to its parent,
 * so a document carries the leaf category together with its ancestors. The
 * GenAI application derives the filterable category ids from that chain, which
 * is why a filter on a parent category also matches its subcategories.
 */
class Category implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?Category $parent = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
        ];
        if ($this->parent !== null) {
            $data['parent'] = $this->parent->jsonSerialize();
        }
        return $data;
    }
}
