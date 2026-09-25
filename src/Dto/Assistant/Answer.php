<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * @codeCoverageIgnore
 */
class Answer
{
    /**
     * @param ?string $id id of the stored answer, used to give feedback;
     *   null if the application did not store it
     * @param AnswerSection[] $sections the parts of the answer; with an
     *   error the hints how to ask more precisely, possibly none
     * @param ?AnswerError $error why the documents did not answer the
     *   question, null if they did
     * @param float $duration runtime of the request in seconds
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly array $sections = [],
        public readonly ?AnswerError $error = null,
        public readonly float $duration = 0.0,
    ) {}
}
