<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\Index\Service\Indexer\IndexUpdateResult;

/**
 * The outcome of one bulk request.
 *
 * The GenAI application answers with the number of documents it wrote and the
 * number of chunks it derived from them. It reports no error per document -
 * a rejected bulk comes back as HTTP 400 and has already become a
 * `GenAiRequestException` by the time this result is built - so a document
 * counts as rejected when it is missing from the count the application
 * returned.
 */
class HttpIndexUpdateResult implements IndexUpdateResult
{
    public function __construct(
        private readonly int $accepted = 0,
        private readonly int $rejected = 0,
        private readonly int $chunks = 0,
    ) {}

    public function getAccepted(): int
    {
        return $this->accepted;
    }

    public function getRejected(): int
    {
        return $this->rejected;
    }

    /**
     * The chunks the application derived from the accepted documents. Not
     * part of the port; the number tells how much was actually embedded.
     */
    public function getChunks(): int
    {
        return $this->chunks;
    }

    public function isSuccess(): bool
    {
        return $this->rejected === 0;
    }

    public function getErrorMessage(): ?string
    {
        if ($this->isSuccess()) {
            return null;
        }
        return $this->rejected . ' of '
            . ($this->accepted + $this->rejected)
            . ' documents were not indexed';
    }
}
