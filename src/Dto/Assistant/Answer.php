<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * @codeCoverageIgnore
 */
class Answer
{
    /**
     * @param AnswerSource[] $sources
     * @param float $duration runtime of the request in seconds
     */
    public function __construct(
        public readonly string $text,
        public readonly array $sources = [],
        public readonly ?string $conversationId = null,
        public readonly float $duration = 0.0,
    ) {}
}
