<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenAiDocumentFactory::class)]
class GenAiDocumentFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $factory = new GenAiDocumentFactory();

        $this->assertInstanceOf(
            GenAiDocument::class,
            $factory->create(),
            'unexpected document',
        );
    }
}
