<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\Index\Service\Indexer\IndexUpdateResult;

class HttpIndexUpdateResult implements IndexUpdateResult
{
    /**
     * @param array<string,string> $errors error message per document id
     */
    public function __construct(
        private readonly int $accepted = 0,
        private readonly int $rejected = 0,
        private readonly array $errors = [],
    ) {}

    public function getAccepted(): int
    {
        return $this->accepted;
    }

    public function getRejected(): int
    {
        return $this->rejected;
    }

    public function isSuccess(): bool
    {
        return $this->rejected === 0 && empty($this->errors);
    }

    public function getErrorMessage(): ?string
    {
        if ($this->isSuccess()) {
            return null;
        }
        if (empty($this->errors)) {
            return $this->rejected . ' documents were rejected';
        }
        $messages = [];
        foreach ($this->errors as $id => $message) {
            $messages[] = $id . ': ' . $message;
        }
        return $this->rejected . ' documents were rejected - '
            . implode(', ', $messages);
    }
}
