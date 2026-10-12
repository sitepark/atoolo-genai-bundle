<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant\Filter;

/**
 * The documents that meet at least one of the conditions.
 */
class OrFilter extends QuestionFilter
{
    /**
     * @param QuestionFilter[] $filters
     */
    public function __construct(
        public readonly array $filters,
    ) {}

    public function toVariable(): array
    {
        return ['or' => self::toVariables($this->filters)];
    }
}
