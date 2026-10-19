<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer\Config;

use Atoolo\GenAi\Service\Indexer\Config\GenAiConfigChangeHandler;
use Atoolo\GenAi\Service\Indexer\Config\GenAiConfigIndexer;
use Atoolo\Index\Service\Indexer\InternalResourceIndexer;
use Atoolo\Resource\Change\ResourceChange;
use Atoolo\Resource\Change\ResourceChangeDeferredException;
use Atoolo\Resource\Change\ResourceChanges;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenAiConfigChangeHandler::class)]
class GenAiConfigChangeHandlerTest extends TestCase
{
    public function testAChangedGlossarySendsTheConfiguration(): void
    {
        $configIndexer = $this->createMock(GenAiConfigIndexer::class);
        $configIndexer->expects($this->once())->method('send');

        $this->handler(enabled: true, indexing: false, configIndexer: $configIndexer)
            ->handle($this->changed('/genai/glossary/stadtteile.php', 'CONFIG'));
    }

    public function testAChangedKnowledgeEntrySendsTheConfiguration(): void
    {
        $configIndexer = $this->createMock(GenAiConfigIndexer::class);
        $configIndexer->expects($this->once())->method('send');

        $this->handler(enabled: true, indexing: false, configIndexer: $configIndexer)
            ->handle($this->changed('/genai/knowledge/bahnhof-21.php', 'CONFIG'));
    }

    public function testAChangeWithoutPublicationTypeNamesTheConfigurationDirectory(): void
    {
        $configIndexer = $this->createMock(GenAiConfigIndexer::class);
        $configIndexer->expects($this->once())->method('send');

        $this->handler(enabled: true, indexing: false, configIndexer: $configIndexer)
            ->handle($this->changed('/configs/genai/glossary/stadtteile.php'));
    }

    public function testOtherChangesSendNothing(): void
    {
        $configIndexer = $this->createMock(GenAiConfigIndexer::class);
        $configIndexer->expects($this->never())->method('send');
        $handler = $this->handler(enabled: true, indexing: false, configIndexer: $configIndexer);

        $handler->handle($this->changed('/leben/buergerservice.php'));
        $handler->handle(
            $this->changed('/genai/glossary/stadtteile.php.translations/en_US.php', 'CONFIG'),
        );
        $handler->handle($this->changed('/genai/glossary/stadtteile.php', 'OBJECT'));
        $handler->handle($this->changed('/genai/glossary/stadtteile.php'));
        $handler->handle($this->changed('/other/settings.php', 'CONFIG'));
        $handler->handle(new ResourceChanges([], ['40540']));
    }

    public function testAChangeWaitsForAFullRun(): void
    {
        $configIndexer = $this->createMock(GenAiConfigIndexer::class);
        $configIndexer->expects($this->never())->method('send');

        $this->expectException(ResourceChangeDeferredException::class);
        $this->handler(enabled: true, indexing: true, configIndexer: $configIndexer)
            ->handle($this->changed('/genai/glossary/stadtteile.php', 'CONFIG'));
    }

    public function testWithoutGenAiIndexerNothingIsSent(): void
    {
        $configIndexer = $this->createMock(GenAiConfigIndexer::class);
        $configIndexer->expects($this->never())->method('send');

        $this->handler(enabled: false, indexing: false, configIndexer: $configIndexer)
            ->handle($this->changed('/configs/genai/glossary/stadtteile.php'));
    }

    private function handler(
        bool $enabled,
        bool $indexing,
        GenAiConfigIndexer $configIndexer,
    ): GenAiConfigChangeHandler {
        $indexer = $this->createStub(InternalResourceIndexer::class);
        $indexer->method('enabled')->willReturn($enabled);
        $indexer->method('isIndexing')->willReturn($indexing);
        return new GenAiConfigChangeHandler($indexer, $configIndexer);
    }

    private function changed(string $path, ?string $publicationType = null): ResourceChanges
    {
        return new ResourceChanges([new ResourceChange('1', $path, $publicationType)]);
    }
}
