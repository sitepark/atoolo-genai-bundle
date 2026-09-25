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
     * @param ?ResourceLanguage $lang language of the question, the one of
     *   the channel if not given
     * @param string[] $categoryIds category ids the retrieved documents are
     *   restricted to; a parent category also matches its subcategories
     */
    public function __construct(
        public readonly string $text,
        ?ResourceLanguage $lang = null,
        public readonly array $categoryIds = [],
    ) {
        $this->lang = $lang ?? ResourceLanguage::default();
    }
}
