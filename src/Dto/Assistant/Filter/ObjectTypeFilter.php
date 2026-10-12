<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant\Filter;

/**
 * The documents of one of the object types.
 */
class ObjectTypeFilter extends QuestionFilter
{
    /**
     * @param string[] $objectTypes
     */
    public function __construct(
        public readonly array $objectTypes,
    ) {}

    public function toVariable(): array
    {
        return ['objectTypes' => array_values($this->objectTypes)];
    }
}
