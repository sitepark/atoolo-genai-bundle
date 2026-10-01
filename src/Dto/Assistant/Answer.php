<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * The answer of the GenAI application to a question.
 *
 * @codeCoverageIgnore
 */
class Answer extends QuestionResult
{
    /**
     * @param AnswerSection[] $sections the parts of the answer
     */
    public function __construct(
        ?string $id = null,
        ?string $feedbackToken = null,
        public readonly array $sections = [],
        float $duration = 0.0,
    ) {
        parent::__construct($id, $feedbackToken, $duration);
    }
}
