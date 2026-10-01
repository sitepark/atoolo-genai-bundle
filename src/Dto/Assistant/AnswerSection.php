<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * One part of an answer: an {@see AnswerTextSection} or an
 * {@see AnswerLinksSection}.
 *
 * @codeCoverageIgnore
 */
abstract class AnswerSection
{
    /**
     * @param AnswerSource[] $sources the documents the content comes from
     */
    public function __construct(
        public readonly string $headline = '',
        public readonly array $sources = [],
    ) {}
}
