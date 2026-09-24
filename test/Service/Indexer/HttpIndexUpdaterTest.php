<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\GenAi\Service\Indexer\HttpIndexUpdater;
use Atoolo\Index\Service\Indexer\IndexDocument;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceTenant;
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
            new GenAiDocumentFactory($this->createResourceChannel()),
        );

        $this->expectException(InvalidArgumentException::class);
        $updater->addDocument($this->createStub(IndexDocument::class));
    }

    public function testBulkIsSentAsAList(): void
    {
        $requests = [];
        $client = new MockHttpClient(
            static function (
                string $method,
                string $url,
                array $options,
            ) use (&$requests): MockResponse {
                $requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => (string) ($options['body'] ?? ''),
                ];
                return new MockResponse('{"documents":1,"chunks":4}');
            },
        );
        $updater = new HttpIndexUpdater(
            new GenAiHttpClient($client, 'https://genai.example.com'),
            new GenAiDocumentFactory($this->createResourceChannel()),
        );

        $doc = $updater->createDocument();
        $doc->id = '7';
        $updater->addDocument($doc);
        $updater->update();
        $hash = $doc->contentHash();

        $this->assertEquals('POST', $requests[0]['method'], 'unexpected method');
        $this->assertEquals(
            'https://genai.example.com/api/index/documents',
            $requests[0]['url'],
            'unexpected url',
        );
        $this->assertEquals(
            '[{"type":"article","id":"7","channel":"www","hash":"'
                . $hash . '"}]',
            $requests[0]['body'],
            'the documents should be sent as a bare list',
        );
    }

    public function testCountsAreTakenFromTheResponse(): void
    {
        $requests = 0;
        $updater = $this->createUpdater(
            '{"documents":1,"chunks":4}',
            $requests,
        );
        $updater->addDocument($updater->createDocument());

        $result = $updater->update();

        $this->assertTrue($result->isSuccess(), 'should be a success');
        $this->assertEquals(1, $result->getAccepted(), 'unexpected accepted');
        $this->assertEquals(4, $result->getChunks(), 'unexpected chunks');
    }

    public function testDocumentsMissingFromTheCountAreRejected(): void
    {
        $requests = 0;
        $updater = $this->createUpdater(
            '{"documents":1,"chunks":2}',
            $requests,
        );
        $updater->addDocument($updater->createDocument());
        $updater->addDocument($updater->createDocument());

        $result = $updater->update();

        $this->assertFalse($result->isSuccess(), 'should not be a success');
        $this->assertEquals(1, $result->getRejected(), 'unexpected rejected');
    }

    public function testUnchangedDocumentsAreNotRejected(): void
    {
        $requests = 0;
        $updater = $this->createUpdater(
            '{"documents":1,"chunks":2,"unchanged":2}',
            $requests,
        );
        $updater->addDocument($updater->createDocument());
        $updater->addDocument($updater->createDocument());
        $updater->addDocument($updater->createDocument());

        $result = $updater->update();

        $this->assertTrue(
            $result->isSuccess(),
            'an unchanged document is no error',
        );
        $this->assertEquals(1, $result->getAccepted(), 'unexpected accepted');
        $this->assertEquals(2, $result->getUnchanged(), 'unexpected unchanged');
    }

    public function testClearDocumentsDropsTheBuffer(): void
    {
        $requests = 0;
        $updater = $this->createUpdater('{"documents":1}', $requests);
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

    public function testResponseWithoutCountsRejectsEverything(): void
    {
        $requests = 0;
        $updater = $this->createUpdater('{}', $requests);
        $updater->addDocument($updater->createDocument());

        $result = $updater->update();

        $this->assertFalse(
            $result->isSuccess(),
            'without a count nothing is known to have been written',
        );
        $this->assertEquals(1, $requests, 'the bulk should be sent once');
    }

    private function createUpdater(
        string $body,
        int &$requests,
    ): HttpIndexUpdater {
        $client = new MockHttpClient(
            static function () use ($body, &$requests): MockResponse {
                $requests++;
                return new MockResponse($body);
            },
        );
        return new HttpIndexUpdater(
            new GenAiHttpClient($client, 'https://genai.example.com'),
            new GenAiDocumentFactory($this->createResourceChannel()),
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
