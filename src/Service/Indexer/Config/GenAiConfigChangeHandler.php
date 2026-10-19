<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\Config;

use Atoolo\Index\Service\Indexer\InternalResourceIndexer;
use Atoolo\Resource\Change\ResourceChange;
use Atoolo\Resource\Change\ResourceChangeDeferredException;
use Atoolo\Resource\Change\ResourceChangeHandler;
use Atoolo\Resource\Change\ResourceChanges;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sends the glossaries and knowledge entries of `configs/genai` again when
 * the CMS reports a change of one of them. The indexer of the resources only
 * indexes the files of the resource directory, so it passes over the changed
 * configuration.
 *
 * The CMS reports the file of a configuration with the publication type
 * `CONFIG` and its path below the configuration directory, e.g.
 * `/genai/glossary/stadtteile.php`. A change without publication type counts
 * if its path names the configuration directory, e.g.
 * `/configs/genai/glossary/stadtteile.php`.
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
     * A German file of a glossary or knowledge entry below the configuration
     * directory; the translations lie a level deeper and do not match.
     */
    private const PATH = '#^/genai/(glossary|knowledge)/[^/]+\\.php$#';

    /** The same file, reported without publication type. */
    private const UNTYPED_PATH = '#^/configs/genai/(glossary|knowledge)/[^/]+\\.php$#';

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
        foreach ($changes->changed as $change) {
            $pattern = match ($change->publicationType) {
                ResourceChange::PUBLICATION_TYPE_CONFIG => self::PATH,
                null => self::UNTYPED_PATH,
                default => null,
            };
            if ($pattern !== null && preg_match($pattern, $change->path) === 1) {
                return true;
            }
        }
        return false;
    }
}
