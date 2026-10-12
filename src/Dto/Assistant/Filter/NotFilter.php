<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant\Filter;

/**
 * The documents that do not meet the condition. The application keeps a
 * document for which the condition is unknown, e.g. one without object
 * type.
 */
class NotFilter extends QuestionFilter
{
    public function __construct(
        public readonly QuestionFilter $filter,
    ) {}

    public function toVariable(): array
    {
        return ['not' => $this->filter->toVariable()];
    }
}
