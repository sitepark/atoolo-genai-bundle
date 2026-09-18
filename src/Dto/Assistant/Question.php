<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

use Atoolo\Resource\ResourceLanguage;

/**
 * @codeCoverageIgnore
 */
class Question
{
    public readonly ResourceLanguage $lang;

    /**
     * @param string[] $categories category ids the answer is limited to
     */
    public function __construct(
        public readonly string $text,
        ?ResourceLanguage $lang = null,
        public readonly ?string $conversationId = null,
        public readonly array $categories = [],
    ) {
        $this->lang = $lang ?? ResourceLanguage::default();
    }
}
