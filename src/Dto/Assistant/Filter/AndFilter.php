<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant\Filter;

/**
 * The documents that meet all of the conditions.
 */
class AndFilter extends QuestionFilter
{
    /**
     * @param QuestionFilter[] $filters
     */
    public function __construct(
        public readonly array $filters,
    ) {}

    public function toVariable(): array
    {
        return ['and' => self::toVariables($this->filters)];
    }
}
