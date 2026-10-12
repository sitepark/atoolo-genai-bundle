<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant\Filter;

/**
 * The documents of one of the categories; a parent category also matches
 * its subcategories.
 */
class CategoryFilter extends QuestionFilter
{
    /**
     * @param string[] $categoryIds
     */
    public function __construct(
        public readonly array $categoryIds,
    ) {}

    public function toVariable(): array
    {
        return ['categoryIds' => array_values($this->categoryIds)];
    }
}
