<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * What the content of an answer section comes from: a document of the
 * website, or the internal knowledge base, a knowledge entry without URL.
 *
 * @codeCoverageIgnore
 */
class AnswerSource
{
    /**
     * @param ?string $url the URL of a document, null for the internal
     *     knowledge base, which has no page to link
     */
    public function __construct(
        public readonly ?string $url,
        public readonly string $title = '',
        public readonly AnswerSourceKind $kind = AnswerSourceKind::DOCUMENT,
    ) {}
}
