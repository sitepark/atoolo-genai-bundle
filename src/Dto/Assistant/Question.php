<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

use Atoolo\GenAi\Dto\Assistant\Filter\QuestionFilter;
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
     * @param bool $archive whether archived documents may answer the
     *   question as well
     * @param ?QuestionFilter[] $filter further conditions on the documents,
     *   combined with AND; null for the default filter of the project, an
     *   empty list for none at all
     */
    public function __construct(
        public readonly string $text,
        ?ResourceLanguage $lang = null,
        public readonly array $categoryIds = [],
        public readonly bool $archive = false,
        public readonly ?array $filter = null,
    ) {
        $this->lang = $lang ?? ResourceLanguage::default();
    }
}
