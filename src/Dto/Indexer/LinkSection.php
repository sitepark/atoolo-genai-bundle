<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Indexer;

/**
 * A list of links. The GenAI application does not embed them; it attaches
 * them as metadata to the chunks of the preceding text section.
 */
class LinkSection extends ContentSection
{
    /**
     * @param Link[] $links
     */
    public function __construct(
        string $headline,
        public readonly array $links,
    ) {
        parent::__construct($headline);
    }

    public function getType(): string
    {
        return 'links';
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [
            'links' => array_map(
                static fn(Link $link): array => $link->jsonSerialize(),
                array_values($this->links),
            ),
        ];
    }
}
