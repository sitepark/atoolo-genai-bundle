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
        private readonly string $index,
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
            $payload[] = $document->getFields();
        }

        $response = $this->client->request(
            'PUT',
            'indices/' . GenAiHttpClient::encodeIndex($this->index)
            . '/documents',
            ['documents' => $payload],
        );

        /** @var array<string,string> $errors */
        $errors = is_array($response['errors'] ?? null)
            ? $response['errors']
            : [];

        return new HttpIndexUpdateResult(
            is_int($response['accepted'] ?? null) ? $response['accepted'] : 0,
            is_int($response['rejected'] ?? null) ? $response['rejected'] : 0,
            $errors,
        );
    }
}
