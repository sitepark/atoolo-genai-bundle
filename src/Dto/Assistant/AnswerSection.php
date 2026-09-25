<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * One part of an answer. A TEXT section carries its content in `html`, a
 * LINKS section in `links`; the field that does not apply is empty.
 *
 * @codeCoverageIgnore
 */
class AnswerSection
{
    /**
     * @param AnswerLink[] $links
     * @param AnswerSource[] $sources the documents the content comes from
     * @param string[] $questions questions the user probably meant, only
     *   given with the hints of an answer with an error
     */
    public function __construct(
        public readonly AnswerSectionType $type,
        public readonly string $headline = '',
        public readonly string $html = '',
        public readonly array $links = [],
        public readonly array $sources = [],
        public readonly array $questions = [],
    ) {}
}
