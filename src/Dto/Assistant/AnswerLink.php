<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * @codeCoverageIgnore
 */
class AnswerLink
{
    /**
     * @param string $label the text to link with, empty if the source
     *   offers none
     */
    public function __construct(
        public readonly string $url,
        public readonly string $label = '',
    ) {}
}
