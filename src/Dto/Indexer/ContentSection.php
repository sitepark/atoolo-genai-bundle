<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Indexer;

use JsonSerializable;

/**
 * A section of an article.
 *
 * The GenAI application selects the shape by the `type` property, so every
 * section names its own type.
 */
abstract class ContentSection implements JsonSerializable
{
    public function __construct(
        public readonly string $headline = '',
    ) {}

    abstract public function getType(): string;

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->getType()];
        if ($this->headline !== '') {
            $data['headline'] = $this->headline;
        }
        return $data;
    }
}
