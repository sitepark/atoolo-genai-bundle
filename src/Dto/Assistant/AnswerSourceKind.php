<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * What an {@see AnswerSource} is. The case names are those of the GenAI
 * application.
 */
enum AnswerSourceKind: string
{
    /** A document of the website, linked by its URL. */
    case DOCUMENT = 'DOCUMENT';

    /**
     * A knowledge entry: a text without a page of its own, named as the
     * internal knowledge base and not linked.
     */
    case KNOWLEDGE = 'KNOWLEDGE';
}
