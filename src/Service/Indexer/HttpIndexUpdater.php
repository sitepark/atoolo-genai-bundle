<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\Index\Service\Indexer\IndexDocument;
use Atoolo\Index\Service\Indexer\IndexUpdater;
use Atoolo\Index\Service\Indexer\IndexUpdateResult;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Buffers the documents of one chunk and sends them as one bulk request.
 *
 * A document without content beyond its title ({@see ContentBeyondTitle})
 * is not sent but deleted: the updater is the first to see the finished
 * document, with what the enrichers of other bundles added, and an article
 * that became empty has to leave the index with the incremental update, not
 * only with the purge of the next full run.
 */
class HttpIndexUpdater implements IndexUpdater
{
    /**
     * @var GenAiDocument[]
     */
    private array $documents = [];

    /**
     * The ids of the documents without content, by channel and source.
     *
     * @var array<string,array<string,string[]>>
     */
    private array $emptyIds = [];

    private readonly ContentBeyondTitle $contentBeyondTitle;

    public function __construct(
        private readonly GenAiHttpClient $client,
        private readonly GenAiDocumentFactory $documentFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->contentBeyondTitle = new ContentBeyondTitle();
    }

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
        if (!$this->contentBeyondTitle->suffices($document)) {
            $this->logger->info(
                'document {id}: {title} has no content beyond its title,'
                . ' not indexed',
                ['id' => $document->id, 'title' => $document->title],
            );
            // without them there is nothing that could be deleted
            if (
                $document->id !== null
                && $document->channel !== null
                && $document->source !== null
            ) {
                $this->emptyIds[$document->channel][$document->source][]
                    = $document->id;
            }
            return;
        }
        $this->documents[] = $document;
    }

    public function clearDocuments(): void
    {
        $this->documents = [];
        $this->emptyIds = [];
    }

    public function update(): IndexUpdateResult
    {
        $documents = $this->documents;
        $emptyIds = $this->emptyIds;
        $this->documents = [];
        $this->emptyIds = [];

        foreach ($emptyIds as $channel => $idsBySource) {
            foreach ($idsBySource as $source => $ids) {
                $this->client->request(
                    'POST',
                    'api/index/documents/delete',
                    [
                        'channel' => (string) $channel,
                        'source' => (string) $source,
                        'ids' => $ids,
                    ],
                );
            }
        }

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
