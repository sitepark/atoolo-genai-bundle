<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\GenAi\Service\Indexer\HttpIndexUpdater;
use Atoolo\Index\Service\Indexer\IndexDocument;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpIndexUpdater::class)]
class HttpIndexUpdaterTest extends TestCase
{
    public function testAddForeignDocument(): void
    {
        $updater = new HttpIndexUpdater(
            new GenAiHttpClient(
                new MockHttpClient(new MockResponse('{}')),
                'https://genai.example.com',
            ),
            new GenAiDocumentFactory(),
            'www',
        );

        $this->expectException(InvalidArgumentException::class);
        $updater->addDocument($this->createStub(IndexDocument::class));
    }

    public function testRejectedDocumentsAreReported(): void
    {
        $updater = new HttpIndexUpdater(
            new GenAiHttpClient(
                new MockHttpClient(new MockResponse(
                    '{"accepted":1,"rejected":1,"errors":{"7":"too long"}}',
                )),
                'https://genai.example.com',
            ),
            new GenAiDocumentFactory(),
            'www',
        );
        $updater->addDocument($updater->createDocument());

        $result = $updater->update();

        $this->assertFalse($result->isSuccess(), 'should not be a success');
        $this->assertStringContainsString(
            '7: too long',
            (string) $result->getErrorMessage(),
            'the error message should name the document',
        );
    }
}
