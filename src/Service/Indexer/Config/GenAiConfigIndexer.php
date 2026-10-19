<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\Config;

use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\GenAi\Service\Indexer\HttpIndexUpdateResult;
use Atoolo\GenAi\Service\Indexer\HttpIndexUpdater;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sends the glossaries and knowledge entries of `configs/genai` to the GenAI
 * application, see {@see GenAiConfigDocuments}: all of them for a full run,
 * the changed files for a change the CMS reports. The application compares
 * their hashes, so an unchanged one is neither written nor embedded again, it
 * only moves to the new run.
 */
class GenAiConfigIndexer
{
    /** The documents of one request. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly GenAiConfigDocuments $documents,
        private readonly GenAiHttpClient $client,
        private readonly GenAiDocumentFactory $documentFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Sends the documents for the run `$processId`.
     *
     * @return int the number of documents sent
     */
    public function send(string $processId): int
    {
        return $this->sendDocuments($this->documents->documents($processId));
    }

    /**
     * Sends the documents of the given files, relative to the configuration
     * directory, for the run `$processId`.
     *
     * @param list<string> $paths
     * @return int the number of documents sent
     */
    public function sendFiles(array $paths, string $processId): int
    {
        return $this->sendDocuments($this->documents->documentsOf($paths, $processId));
    }

    /**
     * @param list<GenAiDocument> $documents
     */
    private function sendDocuments(array $documents): int
    {
        foreach (array_chunk($documents, self::CHUNK_SIZE) as $chunk) {
            $updater = new HttpIndexUpdater(
                $this->client,
                $this->documentFactory,
                $this->logger,
            );
            foreach ($chunk as $document) {
                $updater->addDocument($document);
            }
            $result = $updater->update();
            if (!$result instanceof HttpIndexUpdateResult) {
                continue;
            }
            $this->logger->info(
                'genai config documents sent: {accepted} written, {unchanged} unchanged,'
                . ' {rejected} rejected',
                [
                    'accepted' => $result->getAccepted(),
                    'unchanged' => $result->getUnchanged(),
                    'rejected' => $result->getRejected(),
                ],
            );
        }
        return count($documents);
    }
}
