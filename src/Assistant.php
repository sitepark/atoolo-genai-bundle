<?php

declare(strict_types=1);

namespace Atoolo\GenAi;

use Atoolo\GenAi\Dto\Assistant\QuestionResult;
use Atoolo\GenAi\Dto\Assistant\AnswerFeedback;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Exception\AssistantException;

/**
 * Asks the GenAI application a question about the indexed resources and
 * passes on the verdict of a user on the answer.
 */
interface Assistant
{
    /**
     * @throws AssistantException
     */
    public function ask(Question $question): QuestionResult;

    /**
     * Sets the feedback of the answer the token was given for; null
     * withdraws it.
     *
     * @param string $feedbackToken the token the answer came with; it binds
     *   the feedback to the user who asked and is only valid for a short
     *   while, 15 minutes by default
     * @return bool false if the token is unknown or expired, or the content
     *   of the answer was deleted
     * @throws AssistantException
     */
    public function feedback(
        string $feedbackToken,
        ?AnswerFeedback $feedback,
    ): bool;
}
