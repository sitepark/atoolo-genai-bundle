<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceTenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenAiDocumentFactory::class)]
class GenAiDocumentFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $factory = new GenAiDocumentFactory($this->createResourceChannel());

        $this->assertInstanceOf(
            GenAiDocument::class,
            $factory->create(),
            'unexpected document',
        );
    }

    public function testTheChannelIsTheIndexOfTheResourceChannel(): void
    {
        $factory = new GenAiDocumentFactory($this->createResourceChannel());

        $this->assertEquals(
            'www',
            $factory->create()->channel,
            'the channel should be the index name of the resource channel',
        );
    }

    private function createResourceChannel(): ResourceChannel
    {
        return new ResourceChannel(
            '',
            'WWW',
            '',
            '',
            false,
            '',
            '',
            '',
            '',
            '',
            'www',
            [],
            new DataBag([]),
            $this->createStub(ResourceTenant::class),
        );
    }
}
