<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Exception;

use RuntimeException;
use Throwable;

/**
 * A question could not be asked or a feedback not be given. Unless it is an
 * {@see AssistantErrorType::INTERNAL_ERROR}, the message is the one of the
 * GenAI application, meant for the caller.
 */
class AssistantException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly AssistantErrorType $type
            = AssistantErrorType::INTERNAL_ERROR,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
