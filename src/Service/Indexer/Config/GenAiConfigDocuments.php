<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\Config;

use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\Resource\ResourceChannel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Reads the glossaries and knowledge entries the CMS keeps as configuration
 * of the resource channel, in `configs/genai/glossary/*.php` and
 * `configs/genai/knowledge/*.php`, and turns them into GenAI documents of
 * the source `internal`: they are data of the internal resource channel like
 * its articles and media.
 *
 * Every file returns an array and carries the id of its CMS resource, which
 * is the id of the document, so that a deletion of the resource reaches it.
 * Only the German files are read, the translations in `*.translations` are
 * left out, as only German is indexed. A file that cannot be read, has no id
 * or no content is skipped with a warning.
 *
 * ```php
 * // configs/genai/glossary/<name>.php
 * return [
 *     'id' => 40540,
 *     'title' => 'Stadtteile',                // optional, else the file name
 *     'definitions' => [
 *         ['terms' => ['OB'], 'definition' => 'Oberbürgermeister'],
 *     ],
 * ];
 *
 * // configs/genai/knowledge/<name>.php
 * return [
 *     'id' => 40541,
 *     'headline' => 'Stuttgart 21',
 *     'keywords' => ['S21'],                  // optional
 *     'text' => ['modelType' => 'html.richText', 'text' => '<p>…</p>'],
 * ];
 * ```
 */
class GenAiConfigDocuments
{
    /** The source of the documents, the one of the internal resources. */
    public const SOURCE = 'internal';

    /**
     * The path of a German file of a glossary or knowledge entry below the
     * configuration directory, with its kind; the translations lie a level
     * deeper and do not match.
     */
    public const PATH = '#^/genai/(glossary|knowledge)/[^/]+\\.php$#';

    public function __construct(
        private readonly ResourceChannel $resourceChannel,
        private readonly GenAiDocumentFactory $documentFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * The glossaries and knowledge entries of the channel, written for the
     * run `$processId`.
     *
     * @return list<GenAiDocument>
     */
    public function documents(string $processId): array
    {
        $documents = [];
        foreach (['glossary', 'knowledge'] as $kind) {
            foreach ($this->files($kind) as $file) {
                $document = $this->document($kind, $file);
                if ($document !== null) {
                    $documents[] = $this->withRun($document, $processId);
                }
            }
        }
        return $documents;
    }

    /**
     * The glossaries and knowledge entries of the given files, written for
     * the run `$processId`. A path is relative to the configuration
     * directory, e.g. `/genai/glossary/stadtteile.php`; one that names no
     * such file, or one that no longer exists, is left out.
     *
     * @param list<string> $paths
     * @return list<GenAiDocument>
     */
    public function documentsOf(array $paths, string $processId): array
    {
        $documents = [];
        foreach (array_unique($paths) as $path) {
            if (preg_match(self::PATH, $path, $match) !== 1) {
                continue;
            }
            $file = $this->resourceChannel->configDir . $path;
            if (!is_file($file)) {
                continue;
            }
            $document = $this->document($match[1], $file);
            if ($document !== null) {
                $documents[] = $this->withRun($document, $processId);
            }
        }
        return $documents;
    }

    private function document(string $kind, string $file): ?GenAiDocument
    {
        $data = $this->load($file);
        if ($data === null) {
            return null;
        }
        return $kind === 'glossary'
            ? $this->glossary($file, $data)
            : $this->knowledge($file, $data);
    }

    /**
     * The German files of a kind, sorted; the translations lie in
     * directories of their own and are not matched.
     *
     * @return list<string>
     */
    private function files(string $kind): array
    {
        $files = glob($this->resourceChannel->configDir . '/genai/' . $kind . '/*.php');
        if ($files === false) {
            return [];
        }
        $files = array_values(array_filter($files, 'is_file'));
        sort($files);
        return $files;
    }

    /**
     * @return array<mixed>|null
     */
    private function load(string $file): ?array
    {
        try {
            $data = (static fn(string $path): mixed => include $path)($file);
        } catch (Throwable $e) {
            $this->logger->warning('genai config {file} cannot be read, skipped', [
                'file' => $file,
                'exception' => $e,
            ]);
            return null;
        }
        if (!is_array($data)) {
            $this->logger->warning('genai config {file} returns no array, skipped', [
                'file' => $file,
            ]);
            return null;
        }
        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private function glossary(string $file, array $data): ?GenAiDocument
    {
        $id = $this->id($file, $data);
        if ($id === null) {
            return null;
        }
        $entries = [];
        $definitions = is_array($data['definitions'] ?? null) ? $data['definitions'] : [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $terms = $this->strings($definition['terms'] ?? null);
            $expansion = is_string($definition['definition'] ?? null)
                ? trim($definition['definition'])
                : '';
            if (!empty($terms) && $expansion !== '') {
                $entries[] = ['terms' => $terms, 'expansion' => $expansion];
            }
        }
        if (empty($entries)) {
            $this->logger->warning('genai glossary {file} has no entries, skipped', [
                'file' => $file,
            ]);
            return null;
        }
        $document = $this->documentFactory->create();
        $document->type = GenAiDocument::TYPE_GLOSSARY;
        $document->id = $id;
        $document->title = $this->text($data['title'] ?? null)
            ?? basename($file, '.php');
        $document->entries = $entries;
        return $document;
    }

    /**
     * @param array<mixed> $data
     */
    private function knowledge(string $file, array $data): ?GenAiDocument
    {
        $id = $this->id($file, $data);
        if ($id === null) {
            return null;
        }
        $text = is_array($data['text'] ?? null)
            ? $this->text($data['text']['text'] ?? null)
            : $this->text($data['text'] ?? null);
        if ($text === null) {
            $this->logger->warning('genai knowledge {file} has no text, skipped', [
                'file' => $file,
            ]);
            return null;
        }
        $document = $this->documentFactory->create();
        $document->type = GenAiDocument::TYPE_KNOWLEDGE;
        $document->id = $id;
        $document->title = $this->text($data['headline'] ?? null)
            ?? basename($file, '.php');
        $document->intro = $text;
        $document->addKeywords(...$this->strings($data['keywords'] ?? null));
        return $document;
    }

    /**
     * The id of the CMS resource, `null` with a warning if the file has none.
     *
     * @param array<mixed> $data
     */
    private function id(string $file, array $data): ?string
    {
        $id = $data['id'] ?? null;
        if ((is_int($id) || is_string($id)) && trim((string) $id) !== '') {
            return trim((string) $id);
        }
        $this->logger->warning('genai config {file} has no id, skipped', [
            'file' => $file,
        ]);
        return null;
    }

    private function withRun(GenAiDocument $document, string $processId): GenAiDocument
    {
        $document->source = self::SOURCE;
        $document->processId = $processId;
        return $document;
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * The non-empty strings of a list, stripped.
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $item) {
            $text = $this->text($item);
            if ($text !== null) {
                $strings[] = $text;
            }
        }
        return $strings;
    }
}
