<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\Index\Service\Indexer\IndexDocument;
use Atoolo\Index\Service\Indexer\IndexUpdater;
use Atoolo\Index\Service\Indexer\IndexUpdateResult;
use InvalidArgumentException;

/**
 * Buffers the documents of one chunk and sends them as one bulk request.
 */
class HttpIndexUpdater implements IndexUpdater
{
    /**
     * @var GenAiDocument[]
     */
    private array $documents = [];

    public function __construct(
        private readonly GenAiHttpClient $client,
        private readonly GenAiDocumentFactory $documentFactory,
    ) {}

    public function createDocument(): GenAiDocument
    {
        return $this->documentFactory->create();
    }

    public function addDocument(IndexDocument $document): void
    {
        if (!$document instanceof GenAiDocument) {
            throw new InvalidArgumentException(
                'The GenAI index can only index a ' . GenAiDocument::class
                . ', got ' . $document::class,
            );
        }
        $this->documents[] = $document;
    }

    public function clearDocuments(): void
    {
        $this->documents = [];
    }

    public function update(): IndexUpdateResult
    {
        $documents = $this->documents;
        $this->documents = [];

        if (empty($documents)) {
            return new HttpIndexUpdateResult();
        }

        $payload = [];
        foreach ($documents as $document) {
            $payload[] = $document->jsonSerialize();
        }

        $response = $this->client->request(
            'POST',
            'api/index/documents',
            $payload,
        );

        $accepted = is_int($response['documents'] ?? null)
            ? $response['documents']
            : 0;
        $unchanged = is_int($response['unchanged'] ?? null)
            ? $response['unchanged']
            : 0;

        return new HttpIndexUpdateResult(
            $accepted,
            max(0, count($documents) - $accepted - $unchanged),
            is_int($response['chunks'] ?? null) ? $response['chunks'] : 0,
            $unchanged,
        );
    }
}
