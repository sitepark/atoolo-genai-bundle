<?php

declare(strict_types=1);

namespace Atoolo\GenAi\GraphQL;

use Atoolo\GenAi\Dto\Assistant\Filter\AndFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\CategoryFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\NotFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\ObjectTypeFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\OrFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\QuestionFilter;
use Atoolo\GenAi\Exception\AssistantErrorType;
use Atoolo\GenAi\GraphQL\Input\QuestionFilterInput;

/**
 * Creates the {@see QuestionFilter}s of the GraphQL input, as the
 * `FilterListFactory` of the graphql-search-bundle does for the search.
 * Unlike there, an input that sets no or more than one field is refused
 * rather than reduced to its first field, so that an exclusion the caller
 * built wrongly is not silently dropped.
 */
class QuestionFilterFactory
{
    /**
     * @param QuestionFilterInput[] $inputs
     * @return QuestionFilter[]
     * @throws AssistantError BAD_REQUEST if an input sets no or more than
     *   one field
     */
    public function create(array $inputs): array
    {
        return array_values(array_map(
            fn(QuestionFilterInput $input) => $this->createFilter($input),
            $inputs,
        ));
    }

    private function createFilter(QuestionFilterInput $input): QuestionFilter
    {
        $filters = array_filter([
            $input->objectTypes !== null
                ? new ObjectTypeFilter($input->objectTypes)
                : null,
            $input->categoryIds !== null
                ? new CategoryFilter($input->categoryIds)
                : null,
            $input->and !== null
                ? new AndFilter($this->create($input->and))
                : null,
            $input->or !== null
                ? new OrFilter($this->create($input->or))
                : null,
            $input->not !== null
                ? new NotFilter($this->createFilter($input->not))
                : null,
        ]);
        if (count($filters) !== 1) {
            throw new AssistantError(
                'A GenAiQuestionFilter must set exactly one of objectTypes, '
                . 'categoryIds, and, or, not',
                AssistantErrorType::BAD_REQUEST,
            );
        }
        return array_values($filters)[0];
    }
}
