<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Exception;

use RuntimeException;
use Throwable;

class GenAiRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
