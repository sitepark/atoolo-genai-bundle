<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\Index\Service\Indexer\IndexUpdateResultWithUnchanged;

/**
 * The outcome of one bulk request.
 *
 * The GenAI application answers with the number of documents it wrote, the
 * number of chunks it derived from them and the number of documents it left
 * as they were, because their `hash` had not changed. It reports no error per document -
 * a rejected bulk comes back as HTTP 400 and has already become a
 * `GenAiRequestException` by the time this result is built - so a document
 * counts as rejected when it is missing from both counts the application
 * returned.
 */
class HttpIndexUpdateResult implements IndexUpdateResultWithUnchanged
{
    public function __construct(
        private readonly int $accepted = 0,
        private readonly int $rejected = 0,
        private readonly int $chunks = 0,
        private readonly int $unchanged = 0,
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

    /**
     * The documents the application did not embed again, because their
     * content was unchanged; it only took over their new `processId`. The
     * indexer shows them as `unchanged` in its status.
     */
    public function getUnchanged(): int
    {
        return $this->unchanged;
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
            . ($this->accepted + $this->unchanged + $this->rejected)
            . ' documents were not indexed';
    }
}
