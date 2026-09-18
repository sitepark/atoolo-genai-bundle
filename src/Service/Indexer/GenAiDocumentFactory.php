<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\Index\Service\Indexer\IndexDocumentFactory;

/**
 * Creates the GenAI document. The same factory feeds the
 * {@see HttpIndexUpdater} and the document dumper of the source `genai`, so
 * that a dump always shows what an index run writes.
 */
class GenAiDocumentFactory implements IndexDocumentFactory
{
    public function create(): GenAiDocument
    {
        return new GenAiDocument();
    }
}
