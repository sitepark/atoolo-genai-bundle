<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Indexer;

/**
 * A section with a headline and rich text as an HTML fragment.
 *
 * The GenAI application converts the HTML block by block into Markdown and
 * fills its chunks with those blocks, so the markup is worth keeping: it is
 * what tells headings, paragraphs and lists apart.
 */
class TextSection extends ContentSection
{
    public function __construct(
        string $headline,
        public readonly string $html,
    ) {
        parent::__construct($headline);
    }

    public function getType(): string
    {
        return 'text';
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + ['html' => $this->html];
    }
}
