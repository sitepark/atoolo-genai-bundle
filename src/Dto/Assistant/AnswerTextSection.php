<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * Prose, lists or tables of an answer, delivered as HTML.
 *
 * @codeCoverageIgnore
 */
class AnswerTextSection extends AnswerSection
{
    /**
     * @param AnswerSource[] $sources
     */
    public function __construct(
        string $headline = '',
        public readonly string $html = '',
        array $sources = [],
    ) {
        parent::__construct($headline, $sources);
    }
}
