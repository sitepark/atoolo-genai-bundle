<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\Index\Service\Indexer\IndexService;
use Atoolo\Index\Service\Indexer\IndexUpdater;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The GenAI application as an index target.
 *
 * The application separates its indices by the `channel`, the way Solr does
 * by its cores, so the index name of the resource channel is the channel
 * every document, delete and purge is sent with. Within a channel the
 * content is told apart by the `source` the indexer passes to each call.
 */
class HttpIndexService implements IndexService
{
    public function __construct(
        private readonly GenAiHttpClient $client,
        private readonly ResourceChannel $resourceChannel,
        private readonly GenAiDocumentFactory $documentFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * One index per channel: embedding models are multilingual, so the
     * documents of every language go to the same place.
     */
    public function getIndex(ResourceLanguage $lang): string
    {
        return $this->resourceChannel->searchIndex;
    }

    /**
     * The application has no endpoint that lists its channels to an
     * indexer, so the index of this channel is the one and only it manages.
     * Answering with anything else would make the indexer skip every
     * resource.
     *
     * @return string[]
     */
    public function getManagedIndices(): array
    {
        return [$this->getIndex(ResourceLanguage::default())];
    }

    public function updater(ResourceLanguage $lang): IndexUpdater
    {
        return new HttpIndexUpdater(
            $this->client,
            $this->documentFactory,
            $this->logger,
        );
    }

    public function health(): bool
    {
        $response = $this->client->request('GET', 'actuator/health');
        return ($response['status'] ?? null) === 'UP';
    }

    /**
     * The GenAI application keeps no error protocol of its own, so there is
     * nothing to prepare.
     */
    public function prepareIndexing(
        ResourceLanguage $lang,
        string $source,
    ): void {}

    public function deleteExcludingProcessId(
        ResourceLanguage $lang,
        string $source,
        string $processId,
    ): void {
        $this->client->request(
            'POST',
            'api/index/purge',
            [
                'channel' => $this->getIndex($lang),
                'source' => $source,
                'keepProcessId' => $processId,
            ],
        );
    }

    /**
     * @param string[] $idList
     */
    public function deleteByIdListForAllLanguages(
        string $source,
        array $idList,
    ): void {
        if (empty($idList)) {
            return;
        }
        $this->client->request(
            'POST',
            'api/index/documents/delete',
            [
                'channel' => $this->getIndex(ResourceLanguage::default()),
                'source' => $source,
                'ids' => array_values($idList),
            ],
        );
    }

    /**
     * Documents are searchable as soon as the bulk request returns, so there
     * is nothing to commit.
     */
    public function commit(ResourceLanguage $lang): void {}

    public function commitForAllLanguages(): void {}
}
