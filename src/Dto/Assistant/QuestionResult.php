<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * What the GenAI application returns for a question: an {@see Answer} or
 * one of the errors that say why the question was not answered. Every
 * result is stored and can be rated, an error as well as an answer; only
 * an answer is cached.
 *
 * @codeCoverageIgnore
 */
abstract class QuestionResult
{
    /**
     * @param ?string $id id of the stored result; null if the application
     *   did not store it
     * @param ?string $feedbackToken token that lets the user who asked rate
     *   the result for a short while, 15 minutes by default; null if it
     *   cannot be rated
     * @param float $duration runtime of the request in seconds
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $feedbackToken = null,
        public readonly float $duration = 0.0,
    ) {}
}
