<?php

declare(strict_types=1);

namespace Atoolo\GenAi\GraphQL\Input;

use Overblog\GraphQLBundle\Annotation as GQL;

/**
 * A condition on the documents that answer a question, built like the
 * `InputFilter` of the graphql-search-bundle: exactly one field is set,
 * several values of a list are combined with OR. The documents of a type
 * are left out with `not: {objectTypes: [...]}`.
 *
 * @codeCoverageIgnore
 */
#[GQL\Input(name: 'GenAiQuestionFilter')]
#[GQL\Description(
    'A condition on the resources that answer the question. Exactly one '
    . 'field is set; the values of a list are combined with OR.',
)]
class QuestionFilterInput
{
    /**
     * @var ?array<string>
     */
    #[GQL\Field(type: '[String!]')]
    #[GQL\Description('The object types a resource must have one of.')]
    public ?array $objectTypes = null;

    /**
     * @var ?array<string>
     */
    #[GQL\Field(type: '[String!]')]
    #[GQL\Description(
        'The categories a resource must belong to one of; a parent '
        . 'category also matches its subcategories.',
    )]
    public ?array $categoryIds = null;

    /**
     * @var ?array<QuestionFilterInput>
     */
    #[GQL\Field(type: '[GenAiQuestionFilter!]')]
    #[GQL\Description('Conditions that must all be met.')]
    public ?array $and = null;

    /**
     * @var ?array<QuestionFilterInput>
     */
    #[GQL\Field(type: '[GenAiQuestionFilter!]')]
    #[GQL\Description('Conditions of which at least one must be met.')]
    public ?array $or = null;

    #[GQL\Field(type: 'GenAiQuestionFilter')]
    #[GQL\Description('A condition that must not be met.')]
    public ?QuestionFilterInput $not = null;
}
