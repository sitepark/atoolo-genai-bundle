<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * A list of links of an answer, each with a label.
 *
 * @codeCoverageIgnore
 */
class AnswerLinksSection extends AnswerSection
{
    /**
     * @param AnswerLink[] $links
     * @param AnswerSource[] $sources
     */
    public function __construct(
        string $headline = '',
        public readonly array $links = [],
        array $sources = [],
    ) {
        parent::__construct($headline, $sources);
    }
}
