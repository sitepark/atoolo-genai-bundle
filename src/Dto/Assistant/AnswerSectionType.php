<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * What an answer section contains. The case names are those of the GenAI
 * application.
 */
enum AnswerSectionType: string
{
    /** Prose, lists or tables, delivered as HTML. */
    case TEXT = 'TEXT';
    /** A list of links, each with a label. */
    case LINKS = 'LINKS';
}
