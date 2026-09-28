<?php

declare(strict_types=1);

namespace Atoolo\GenAi;

use Atoolo\GenAi\Dto\Assistant\Answer;
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
    public function ask(Question $question): Answer;

    /**
     * Sets the feedback of a stored answer; null withdraws it.
     *
     * @param string $feedbackToken the token the answer came with; it binds
     *   the feedback to the user who asked and is only valid for a short
     *   while, 15 minutes by default
     * @return bool false if the application knows no answer with this id,
     *   the token is unknown, expired or was given for another answer, or
     *   the content of the answer was deleted
     * @throws AssistantException
     */
    public function feedback(
        string $answerId,
        string $feedbackToken,
        ?AnswerFeedback $feedback,
    ): bool;
}
