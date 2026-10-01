<?php

declare(strict_types=1);

namespace Atoolo\GenAi\GraphQL;

use Atoolo\GenAi\Assistant as GenAiAssistant;
use Atoolo\GenAi\Dto\Assistant\AnswerFeedback;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Dto\Assistant\QuestionResult;
use Atoolo\GenAi\Exception\AssistantErrorType;
use Atoolo\GenAi\Exception\AssistantException;
use Atoolo\Resource\ResourceLanguage;
use Overblog\GraphQLBundle\Annotation as GQL;

/**
 * Offers the public part of the GenAI application - asking a question and
 * giving feedback on the answer - as fields of the atoolo GraphQL schema.
 * The request of the caller is not passed on as it is; the
 * {@see GenAiAssistant} sends fixed operations of its own.
 *
 * A failure becomes an {@see AssistantError} with its type in
 * `extensions.classification`. The GenAI application's reason for a
 * BAD_REQUEST or TOO_MANY_REQUESTS is meant for the caller and passed on;
 * such an error is expected and carries no previous exception, which the
 * error logger of overblog would log. An INTERNAL_ERROR would name the
 * address of the application: the caller gets a general message, the
 * original exception is kept as the previous one and so is logged.
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
    #[GQL\Query(name: 'genAiQuestion', type: 'GenAiQuestionResult!')]
    #[GQL\Description(
        'Answers a question using the resources indexed in the GenAI '
        . 'application: a GenAiAnswer, or an error that says why the '
        . 'question was not answered.',
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
    ): QuestionResult {
        try {
            return $this->assistant->ask(new Question(
                $query,
                ResourceLanguage::of($lang),
                $categoryIds ?? [],
            ));
        } catch (AssistantException $e) {
            throw $this->toError($e);
        }
    }

    #[GQL\Mutation(name: 'genAiAnswerFeedback', type: 'Boolean!')]
    #[GQL\Description(
        'Sets the feedback of the answer the feedbackToken was given for; '
        . 'null withdraws it. Works only while the token is valid, 15 '
        . 'minutes by default. False if the token is unknown or expired, or '
        . 'the content of the answer was deleted.',
    )]
    #[GQL\Arg(
        name: 'feedbackToken',
        type: 'String!',
        description: 'The feedbackToken the answer came with.',
    )]
    #[GQL\Arg(name: 'feedback', type: 'GenAiAnswerFeedback')]
    public function answerFeedback(
        string $feedbackToken,
        ?AnswerFeedback $feedback = null,
    ): bool {
        try {
            return $this->assistant->feedback($feedbackToken, $feedback);
        } catch (AssistantException $e) {
            throw $this->toError($e);
        }
    }

    private function toError(AssistantException $e): AssistantError
    {
        if ($e->type !== AssistantErrorType::INTERNAL_ERROR) {
            return new AssistantError($e->getMessage(), $e->type);
        }
        return new AssistantError(
            'The GenAI application is not available',
            $e->type,
            $e,
        );
    }
}
