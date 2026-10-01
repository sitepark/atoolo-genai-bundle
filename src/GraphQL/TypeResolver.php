<?php

declare(strict_types=1);

namespace Atoolo\GenAi\GraphQL;

use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerCutOffError;
use Atoolo\GenAi\Dto\Assistant\AnswerLinksSection;
use Atoolo\GenAi\Dto\Assistant\AnswerTextSection;
use Atoolo\GenAi\Dto\Assistant\NoDocumentsError;
use Atoolo\GenAi\Dto\Assistant\NoMatchingDocumentsError;
use Atoolo\GenAi\Dto\Assistant\UnansweredError;
use UnexpectedValueException;

/**
 * Names the GraphQL type of a value of the union `GenAiQuestionResult` and
 * the interfaces `GenAiAnsweredQuestion` and `GenAiAnswerSection`. The
 * types are defined in YAML, so overblog cannot map them by their PHP class
 * itself; their `resolveType` calls this service. A value has the same type
 * whichever of them asks.
 */
class TypeResolver
{
    public function resolveType(object $value): string
    {
        return match (true) {
            $value instanceof Answer => 'GenAiAnswer',
            $value instanceof NoDocumentsError => 'GenAiNoDocumentsError',
            $value instanceof NoMatchingDocumentsError
                => 'GenAiNoMatchingDocumentsError',
            $value instanceof AnswerCutOffError => 'GenAiAnswerCutOffError',
            $value instanceof UnansweredError => 'GenAiUnansweredError',
            $value instanceof AnswerTextSection => 'GenAiTextSection',
            $value instanceof AnswerLinksSection => 'GenAiLinksSection',
            default => throw new UnexpectedValueException(
                'No GenAI GraphQL type for ' . $value::class,
            ),
        };
    }
}
