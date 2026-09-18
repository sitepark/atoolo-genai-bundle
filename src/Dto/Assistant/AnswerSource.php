<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * A resource the answer was based on.
 *
 * @codeCoverageIgnore
 */
class AnswerSource
{
    public function __construct(
        public readonly string $id,
        public readonly string $url = '',
        public readonly string $title = '',
        public readonly ?float $score = null,
    ) {}
}
