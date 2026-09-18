<?php

declare(strict_types=1);

namespace Atoolo\GenAi;

use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Exception\AssistantException;

/**
 * Asks the GenAI application a question about the indexed resources and
 * returns its answer together with the resources it was based on.
 */
interface Assistant
{
    /**
     * @throws AssistantException
     */
    public function ask(Question $question): Answer;
}
