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
     * @return bool false if the application knows no answer with this id
     * @throws AssistantException
     */
    public function feedback(string $answerId, ?AnswerFeedback $feedback): bool;
}
