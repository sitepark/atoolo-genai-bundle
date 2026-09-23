<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\Index\Service\Indexer\IndexDocumentFactory;
use Atoolo\Resource\ResourceChannel;

/**
 * Creates the GenAI document. The same factory feeds the
 * {@see HttpIndexUpdater} and the document dumper of the source `genai`, so
 * that a dump always shows what an index run writes.
 *
 * The factory fills in the channel, because it is no property of the
 * resource: it is the index the documents are written to, the name
 * {@see HttpIndexService::getIndex()} answers with.
 */
class GenAiDocumentFactory implements IndexDocumentFactory
{
    public function __construct(
        private readonly ResourceChannel $resourceChannel,
    ) {}

    public function create(): GenAiDocument
    {
        $document = new GenAiDocument();
        $document->channel = $this->resourceChannel->searchIndex;
        return $document;
    }
}
