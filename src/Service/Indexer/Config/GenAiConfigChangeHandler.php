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
 * Sends a glossary or knowledge entry of `configs/genai` again when the CMS
 * reports a change of its file; the other files stay as they are. The
 * indexer of the resources only indexes the files of the resource directory,
 * so it passes over the changed configuration.
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
    /** The directory of the configuration, which a path without type names. */
    private const CONFIG_DIR = '/configs';

    public function __construct(
        private readonly InternalResourceIndexer $indexer,
        private readonly GenAiConfigIndexer $configIndexer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function handle(ResourceChanges $changes): void
    {
        $paths = $this->changedPaths($changes);
        if (!$this->indexer->enabled() || empty($paths)) {
            return;
        }
        if ($this->indexer->isIndexing()) {
            throw new ResourceChangeDeferredException(
                'the GenAI indexer is running',
            );
        }
        $sent = $this->configIndexer->sendFiles($paths, uniqid('', true));
        $this->logger->info('genai configuration changed, {count} documents sent', [
            'count' => $sent,
            'paths' => $paths,
        ]);
    }

    /**
     * The changed glossaries and knowledge entries, by their paths below the
     * configuration directory.
     *
     * @return list<string>
     */
    private function changedPaths(ResourceChanges $changes): array
    {
        $paths = [];
        foreach ($changes->changed as $change) {
            $path = match ($change->publicationType) {
                ResourceChange::PUBLICATION_TYPE_CONFIG => $change->path,
                null => str_starts_with($change->path, self::CONFIG_DIR . '/')
                    ? substr($change->path, strlen(self::CONFIG_DIR))
                    : null,
                default => null,
            };
            if ($path !== null && preg_match(GenAiConfigDocuments::PATH, $path) === 1) {
                $paths[$path] = $path;
            }
        }
        return array_values($paths);
    }
}
