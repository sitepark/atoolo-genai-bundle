<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\Config;

use Atoolo\Index\Service\Indexer\InternalResourceIndexer;
use Atoolo\Resource\Change\ResourceChangeDeferredException;
use Atoolo\Resource\Change\ResourceChangeHandler;
use Atoolo\Resource\Change\ResourceChanges;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sends the glossaries and knowledge entries of `configs/genai` again when
 * the CMS reports a change of one of them. The indexer of the resources only
 * finds the paths of the resource directory, so it passes over the changed
 * configuration.
 *
 * A removed glossary or knowledge entry needs nothing of its own: the CMS
 * reports the id of its resource, which is the id of the document, and the
 * GenAI indexer removes it from the source `internal` like any resource.
 *
 * While the GenAI indexer runs a full index, the change is deferred, as by
 * the indexers themselves; the full run sends the configuration anyway.
 */
class GenAiConfigChangeHandler implements ResourceChangeHandler
{
    /**
     * A German file of a glossary or knowledge entry, with or without the
     * leading directory of the configuration; the translations lie a level
     * deeper and do not match.
     */
    private const PATH = '#(^|/)genai/(glossary|knowledge)/[^/]+\\.php$#';

    public function __construct(
        private readonly InternalResourceIndexer $indexer,
        private readonly GenAiConfigIndexer $configIndexer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function handle(ResourceChanges $changes): void
    {
        if (!$this->indexer->enabled() || !$this->concernsConfiguration($changes)) {
            return;
        }
        if ($this->indexer->isIndexing()) {
            throw new ResourceChangeDeferredException(
                'the GenAI indexer is running',
            );
        }
        $sent = $this->configIndexer->send(uniqid('', true));
        $this->logger->info('genai configuration changed, {count} documents sent', [
            'count' => $sent,
        ]);
    }

    private function concernsConfiguration(ResourceChanges $changes): bool
    {
        foreach ($changes->changedPaths() as $path) {
            if (preg_match(self::PATH, $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
