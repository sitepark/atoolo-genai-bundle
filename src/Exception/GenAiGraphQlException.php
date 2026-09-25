<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Exception;

/**
 * The GenAI application refused a GraphQL operation with a list of
 * `errors`. Keeps what the application told the caller: the
 * `extensions.classification` and the message of the first error.
 */
class GenAiGraphQlException extends GenAiRequestException
{
    /**
     * @param ?string $classification e.g. BAD_REQUEST or TOO_MANY_REQUESTS,
     *   null if the application gave none
     * @param string $reason the message of the first error
     */
    public function __construct(
        string $message,
        public readonly ?string $classification = null,
        public readonly string $reason = '',
    ) {
        parent::__construct($message);
    }
}
