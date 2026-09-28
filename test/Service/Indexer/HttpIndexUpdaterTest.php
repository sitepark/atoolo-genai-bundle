<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Dto\Indexer\TextSection;
use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\GenAi\Service\Indexer\HttpIndexUpdater;
use Atoolo\Index\Service\Indexer\IndexDocument;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceTenant;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpIndexUpdater::class)]
class HttpIndexUpdaterTest extends TestCase
{
    private const CONTENT = 'Der Antrag kann online gestellt werden.';

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

        $doc = $this->withContent($updater->createDocument());
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
            [[
                'type' => 'article',
                'id' => '7',
                'channel' => 'www',
                'content' => [
                    ['type' => 'text', 'html' => '<p>' . self::CONTENT . '</p>'],
                ],
                'hash' => $hash,
            ]],
            json_decode($requests[0]['body'], true),
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
        $updater->addDocument($this->withContent($updater->createDocument()));

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
        $updater->addDocument($this->withContent($updater->createDocument()));
        $updater->addDocument($this->withContent($updater->createDocument()));

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
        $updater->addDocument($this->withContent($updater->createDocument()));
        $updater->addDocument($this->withContent($updater->createDocument()));
        $updater->addDocument($this->withContent($updater->createDocument()));

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
        $updater->addDocument($this->withContent($updater->createDocument()));
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
        $updater->addDocument($this->withContent($updater->createDocument()));

        $result = $updater->update();

        $this->assertFalse(
            $result->isSuccess(),
            'without a count nothing is known to have been written',
        );
        $this->assertEquals(1, $requests, 'the bulk should be sent once');
    }

    public function testDocumentWithoutContentIsNotSent(): void
    {
        $requests = [];
        $updater = $this->createRecordingUpdater(
            '{"documents":1,"chunks":1}',
            $requests,
        );
        $doc = $this->withContent($updater->createDocument());
        $doc->id = '1';
        $doc->source = 'internal';
        $updater->addDocument($doc);
        $updater->addDocument($this->createEmptyDocument($updater, '2'));

        $result = $updater->update();

        $this->assertCount(2, $requests, 'a delete and a bulk expected');
        $bulk = $requests[1];
        $this->assertEquals(
            'https://genai.example.com/api/index/documents',
            $bulk['url'],
            'unexpected url of the bulk',
        );
        $this->assertStringContainsString(
            '"id":"1"',
            $bulk['body'],
            'the document with content should be sent',
        );
        $this->assertStringNotContainsString(
            '"id":"2"',
            $bulk['body'],
            'the document without content must not be sent',
        );
        $this->assertTrue(
            $result->isSuccess(),
            'a filtered document is no rejected one',
        );
        $this->assertEquals(0, $result->getRejected(), 'unexpected rejected');
        $this->assertEquals(1, $result->getAccepted(), 'unexpected accepted');
    }

    public function testDocumentWithoutContentIsDeleted(): void
    {
        $requests = [];
        $updater = $this->createRecordingUpdater('{"deleted":2}', $requests);
        $updater->addDocument($this->createEmptyDocument($updater, '2'));
        $updater->addDocument($this->createEmptyDocument($updater, '3'));

        $result = $updater->update();

        $this->assertCount(
            1,
            $requests,
            'only the delete expected, no bulk',
        );
        $this->assertEquals('POST', $requests[0]['method'], 'unexpected method');
        $this->assertEquals(
            'https://genai.example.com/api/index/documents/delete',
            $requests[0]['url'],
            'unexpected url',
        );
        $this->assertEquals(
            '{"channel":"www","source":"internal","ids":["2","3"]}',
            $requests[0]['body'],
            'unexpected body',
        );
        $this->assertTrue($result->isSuccess(), 'unexpected result');
        $this->assertEquals(0, $result->getRejected(), 'unexpected rejected');
    }

    public function testDocumentWithoutContentIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('no content beyond its title'),
                ['id' => '2', 'title' => 'Frauenkurs'],
            );
        $updater = new HttpIndexUpdater(
            new GenAiHttpClient(
                new MockHttpClient(new MockResponse('{}')),
                'https://genai.example.com',
            ),
            new GenAiDocumentFactory($this->createResourceChannel()),
            $logger,
        );

        $updater->addDocument($this->createEmptyDocument($updater, '2'));
    }

    public function testClearDocumentsDropsTheIdsToDelete(): void
    {
        $requests = [];
        $updater = $this->createRecordingUpdater('{}', $requests);
        $updater->addDocument($this->createEmptyDocument($updater, '2'));
        $updater->clearDocuments();

        $updater->update();

        $this->assertCount(0, $requests, 'nothing should be deleted');
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

    /**
     * @param array<int,array{method:string,url:string,body:string}> $requests
     */
    private function createRecordingUpdater(
        string $body,
        array &$requests,
    ): HttpIndexUpdater {
        $client = new MockHttpClient(
            static function (
                string $method,
                string $url,
                array $options,
            ) use ($body, &$requests): MockResponse {
                $requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => (string) ($options['body'] ?? ''),
                ];
                return new MockResponse($body);
            },
        );
        return new HttpIndexUpdater(
            new GenAiHttpClient($client, 'https://genai.example.com'),
            new GenAiDocumentFactory($this->createResourceChannel()),
        );
    }

    private function createEmptyDocument(
        HttpIndexUpdater $updater,
        string $id,
    ): GenAiDocument {
        $doc = $updater->createDocument();
        $doc->id = $id;
        $doc->source = 'internal';
        $doc->title = 'Frauenkurs';
        $doc->content = [new TextSection('', '<p>Frauenkurs A2</p>')];
        return $doc;
    }

    private function withContent(GenAiDocument $doc): GenAiDocument
    {
        $doc->content = [new TextSection('', '<p>' . self::CONTENT . '</p>')];
        return $doc;
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
