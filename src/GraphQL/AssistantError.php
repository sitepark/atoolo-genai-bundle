<?php

declare(strict_types=1);

namespace Atoolo\GenAi\GraphQL;

use Atoolo\GenAi\Exception\AssistantErrorType;
use GraphQL\Error\ProvidesExtensions;
use GraphQL\Error\UserError;
use Throwable;

/**
 * A field error of the assistant, handed to the client with its type in
 * `extensions.classification`, so that a frontend can tell e.g. too many
 * questions from a question that is too long. A user error, so that its
 * message reaches the client and it is never rethrown.
 */
class AssistantError extends UserError implements ProvidesExtensions
{
    public function __construct(
        string $message,
        public readonly AssistantErrorType $type,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array{classification:string}
     */
    public function getExtensions(): array
    {
        return ['classification' => $this->type->value];
    }
}
