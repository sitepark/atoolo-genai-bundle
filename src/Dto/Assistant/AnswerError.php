<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * Why the indexed documents did not answer a question. The case names are
 * those of the GenAI application.
 */
enum AnswerError: string
{
    /** No document was similar enough to the question. */
    case NO_DOCUMENTS = 'NO_DOCUMENTS';
    /** Documents were found, but none of them answers the question. */
    case NO_MATCHING_DOCUMENTS = 'NO_MATCHING_DOCUMENTS';
}
