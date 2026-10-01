<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * An error of the GenAI application this bundle does not know yet. The
 * application may add errors; such a result is kept with its id and token
 * rather than failing.
 *
 * @codeCoverageIgnore
 */
class UnansweredError extends QuestionResult
{
    /**
     * @param string $typeName the type name of the error in the
     *   application, empty if it named none
     */
    public function __construct(
        ?string $id = null,
        ?string $feedbackToken = null,
        public readonly string $typeName = '',
        float $duration = 0.0,
    ) {
        parent::__construct($id, $feedbackToken, $duration);
    }
}
