<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Indexer;

use JsonSerializable;

/**
 * A link of a {@see LinkSection}.
 */
class Link implements JsonSerializable
{
    public function __construct(
        public readonly string $url,
        public readonly string $label = '',
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $data = ['url' => $this->url];
        if ($this->label !== '') {
            $data['label'] = $this->label;
        }
        return $data;
    }
}
