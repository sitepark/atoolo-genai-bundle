<?php

declare(strict_types=1);

namespace Atoolo\GenAi\GraphQL;

use Atoolo\GenAi\Assistant as GenAiAssistant;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerFeedback;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\Resource\ResourceLanguage;
use Overblog\GraphQLBundle\Annotation as GQL;

/**
 * Offers the public part of the GenAI application - asking a question and
 * giving feedback on the answer - as fields of the atoolo GraphQL schema.
 * The request of the caller is not passed on as it is; the
 * {@see GenAiAssistant} sends fixed operations of its own.
 */
#[GQL\Provider]
class Assistant
{
    public function __construct(
        private readonly GenAiAssistant $assistant,
    ) {}

    /**
     * @param string[]|null $categoryIds
     */
    #[GQL\Query(name: 'genAiQuestion', type: 'GenAiAnswer!')]
    #[GQL\Description(
        'Answers a question using the resources indexed in the GenAI '
        . 'application.',
    )]
    #[GQL\Arg(name: 'query', type: 'String!', description: 'The question.')]
    #[GQL\Arg(
        name: 'lang',
        type: 'String',
        description: 'Language of the question, the one of the channel if '
            . 'not given.',
    )]
    #[GQL\Arg(
        name: 'categoryIds',
        type: '[String!]',
        description: 'Restricts the retrieved resources to these categories; '
            . 'a parent category also matches its subcategories, several ids '
            . 'are combined with OR.',
    )]
    public function question(
        string $query,
        ?string $lang = null,
        ?array $categoryIds = null,
    ): Answer {
        return $this->assistant->ask(new Question(
            $query,
            ResourceLanguage::of($lang),
            $categoryIds ?? [],
        ));
    }

    #[GQL\Mutation(name: 'genAiAnswerFeedback', type: 'Boolean!')]
    #[GQL\Description(
        'Sets the feedback of an answer; null withdraws it. False if the '
        . 'answer is unknown.',
    )]
    #[GQL\Arg(
        name: 'answerId',
        type: 'ID!',
        description: 'The id of the answer.',
    )]
    #[GQL\Arg(name: 'feedback', type: 'GenAiAnswerFeedback')]
    public function answerFeedback(
        string $answerId,
        ?AnswerFeedback $feedback = null,
    ): bool {
        return $this->assistant->feedback($answerId, $feedback);
    }
}
