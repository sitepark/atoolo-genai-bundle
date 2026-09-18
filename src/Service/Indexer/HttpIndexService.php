<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\Index\Service\Indexer\IndexService;
use Atoolo\Index\Service\Indexer\IndexUpdater;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;

/**
 * The GenAI application as an index target.
 *
 * One index per channel: embedding models are multilingual, so the documents
 * carry their `language` and `locale` instead of being spread over
 * language specific indices.
 */
class HttpIndexService implements IndexService
{
    public function __construct(
        private readonly GenAiHttpClient $client,
        private readonly ResourceChannel $resourceChannel,
        private readonly GenAiDocumentFactory $documentFactory,
    ) {}

    public function getIndex(ResourceLanguage $lang): string
    {
        return $this->resourceChannel->searchIndex;
    }

    /**
     * @return string[]
     */
    public function getManagedIndices(): array
    {
        $response = $this->client->request('GET', 'indices');

        $indices = [];
        /** @var array<array<string,mixed>> $list */
        $list = is_array($response['indices'] ?? null)
            ? $response['indices']
            : [];
        foreach ($list as $index) {
            if (isset($index['name']) && is_string($index['name'])) {
                $indices[] = $index['name'];
            }
        }
        return $indices;
    }

    public function updater(ResourceLanguage $lang): IndexUpdater
    {
        return new HttpIndexUpdater(
            $this->client,
            $this->documentFactory,
            $this->getIndex($lang),
        );
    }

    public function health(): bool
    {
        $this->client->request('GET', 'health');
        return true;
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
            $this->indexPath($lang) . '/documents/cleanup',
            ['source' => $source, 'process_id' => $processId],
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
            $this->indexPath(ResourceLanguage::default())
            . '/documents/delete',
            ['source' => $source, 'ids' => array_values($idList)],
        );
    }

    public function commit(ResourceLanguage $lang): void
    {
        $this->client->request(
            'POST',
            $this->indexPath($lang) . '/commit',
            [],
        );
    }

    public function commitForAllLanguages(): void
    {
        $this->commit(ResourceLanguage::default());
    }

    private function indexPath(ResourceLanguage $lang): string
    {
        return 'indices/'
            . GenAiHttpClient::encodeIndex($this->getIndex($lang));
    }
}
