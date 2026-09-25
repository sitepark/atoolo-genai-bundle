<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant;

/**
 * A user's verdict on an answer. The case names are those of the GenAI
 * application.
 */
enum AnswerFeedback: string
{
    case GOOD = 'GOOD';
    case BAD = 'BAD';
}
