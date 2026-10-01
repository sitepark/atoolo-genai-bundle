<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * The model was given chunks as context, but according to the model none
 * of them answers the question. It returns hints how to ask more precisely
 * and suggested questions, both possibly empty.
 *
 * @codeCoverageIgnore
 */
class NoMatchingDocumentsError extends QuestionResult
{
    /**
     * @param AnswerTextSection[] $hints how to ask more precisely
     * @param string[] $suggestedQuestions questions the user probably
     *   meant, at most three
     */
    public function __construct(
        ?string $id = null,
        ?string $feedbackToken = null,
        public readonly array $hints = [],
        public readonly array $suggestedQuestions = [],
        float $duration = 0.0,
    ) {
        parent::__construct($id, $feedbackToken, $duration);
    }
}
