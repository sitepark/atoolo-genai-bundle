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

    public function testClearDocumentsDropsTheBuffer(): void
    {
        $requests = 0;
        $updater = $this->createUpdater('{"accepted":1}', $requests);
        $updater->addDocument($updater->createDocument());
        $updater->clearDocuments();

        $result = $updater->update();

        $this->assertTrue($result->isSuccess(), 'unexpected result');
        $this->assertEquals(0, $requests, 'cleared documents must not be sent');
    }

    public function testEmptyBulkSendsNothing(): void
    {
        $requests = 0;
        $updater = $this->createUpdater('{}', $requests);

        $result = $updater->update();

        $this->assertTrue($result->isSuccess(), 'unexpected result');
        $this->assertEquals(0, $requests, 'an empty bulk must not be sent');
    }

    public function testResponseWithoutCountsIsASuccess(): void
    {
        $requests = 0;
        $updater = $this->createUpdater('{}', $requests);
        $updater->addDocument($updater->createDocument());

        $result = $updater->update();

        $this->assertTrue($result->isSuccess(), 'no rejected, no errors');
        $this->assertEquals(1, $requests, 'the bulk should be sent once');
    }

    private function createUpdater(string $body, int &$requests): HttpIndexUpdater
    {
        $client = new MockHttpClient(
            static function () use ($body, &$requests): MockResponse {
                $requests++;
                return new MockResponse($body);
            },
        );
        return new HttpIndexUpdater(
            new GenAiHttpClient($client, 'https://genai.example.com'),
            new GenAiDocumentFactory(),
            'www',
        );
    }
}
