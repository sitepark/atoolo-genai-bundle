<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\GenAiDocumentFactory;
use Atoolo\GenAi\Service\Indexer\HttpIndexService;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceTenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpIndexService::class)]
class HttpIndexServiceTest extends TestCase
{
    /**
     * @var array<int,array{method:string,url:string,body:string}>
     */
    private array $requests = [];

    public function testGetIndexIsTheSameForEveryLanguage(): void
    {
        $service = $this->createService('{}');

        $this->assertEquals(
            'www',
            $service->getIndex(ResourceLanguage::of('en_US')),
            'one index per channel, no language specific indices',
        );
    }

    public function testGetManagedIndices(): void
    {
        $service = $this->createService(
            '{"indices":[{"name":"www","documents":123},{"documents":1}]}',
        );

        $this->assertEquals(
            ['www'],
            $service->getManagedIndices(),
            'unexpected indices',
        );
        $this->assertEquals(
            'https://genai.example.com/api/v1/indices',
            $this->requests[0]['url'],
            'unexpected url',
        );
    }

    public function testUpdaterSendsBulk(): void
    {
        $service = $this->createService('{"accepted":1,"rejected":0}');

        $updater = $service->updater(ResourceLanguage::default());
        $document = $updater->createDocument();
        $this->assertInstanceOf(GenAiDocument::class, $document);
        $document->id = '123';
        $updater->addDocument($document);
        $result = $updater->update();

        $this->assertTrue($result->isSuccess(), 'unexpected result');
        $this->assertEquals(
            'PUT',
            $this->requests[0]['method'],
            'unexpected method',
        );
        $this->assertEquals(
            'https://genai.example.com/api/v1/indices/www/documents',
            $this->requests[0]['url'],
            'unexpected url',
        );
        $this->assertStringContainsString(
            '"id":"123"',
            $this->requests[0]['body'],
            'the document should be part of the bulk',
        );
    }

    public function testEmptyBulkSendsNoRequest(): void
    {
        $service = $this->createService('{}');

        $result = $service->updater(ResourceLanguage::default())->update();

        $this->assertTrue($result->isSuccess(), 'unexpected result');
        $this->assertCount(
            0,
            $this->requests,
            'an empty bulk should not produce a request',
        );
    }

    public function testClearDocuments(): void
    {
        $service = $this->createService('{}');

        $updater = $service->updater(ResourceLanguage::default());
        $updater->addDocument($updater->createDocument());
        $updater->clearDocuments();
        $updater->update();

        $this->assertCount(
            0,
            $this->requests,
            'cleared documents should not be sent',
        );
    }

    public function testDeleteByIdList(): void
    {
        $service = $this->createService('{"deleted":1}');

        $service->deleteByIdListForAllLanguages('genai', ['123']);

        $this->assertEquals(
            'https://genai.example.com/api/v1/indices/www/documents/delete',
            $this->requests[0]['url'],
            'unexpected url',
        );
        $this->assertEquals(
            '{"source":"genai","ids":["123"]}',
            $this->requests[0]['body'],
            'unexpected body',
        );
    }

    public function testDeleteByEmptyIdListSendsNoRequest(): void
    {
        $service = $this->createService('{}');

        $service->deleteByIdListForAllLanguages('genai', []);

        $this->assertCount(
            0,
            $this->requests,
            'nothing to delete should not produce a request',
        );
    }

    public function testDeleteExcludingProcessId(): void
    {
        $service = $this->createService('{"deleted":2}');

        $service->deleteExcludingProcessId(
            ResourceLanguage::default(),
            'genai',
            'p-1',
        );

        $this->assertEquals(
            'https://genai.example.com/api/v1/indices/www/documents/cleanup',
            $this->requests[0]['url'],
            'unexpected url',
        );
        $this->assertEquals(
            '{"source":"genai","process_id":"p-1"}',
            $this->requests[0]['body'],
            'unexpected body',
        );
    }

    public function testCommit(): void
    {
        $service = $this->createService('', 204);

        $service->commitForAllLanguages();

        $this->assertEquals(
            'https://genai.example.com/api/v1/indices/www/commit',
            $this->requests[0]['url'],
            'unexpected url',
        );
    }

    public function testPrepareIndexingIsANoOp(): void
    {
        $service = $this->createService('{}');

        $service->prepareIndexing(ResourceLanguage::default(), 'genai');

        $this->assertCount(
            0,
            $this->requests,
            'there is nothing to prepare on the GenAI side',
        );
    }

    public function testHealth(): void
    {
        $service = $this->createService('{}');

        $this->assertTrue($service->health(), 'unexpected health');
        $this->assertEquals(
            'https://genai.example.com/api/v1/health',
            $this->requests[0]['url'],
            'unexpected url',
        );
    }

    private function createService(
        string $body,
        int $statusCode = 200,
    ): HttpIndexService {
        $requests = &$this->requests;
        $httpClient = new MockHttpClient(
            static function (
                string $method,
                string $url,
                array $options,
            ) use ($body, $statusCode, &$requests): MockResponse {
                $requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => (string) ($options['body'] ?? ''),
                ];
                return new MockResponse($body, ['http_code' => $statusCode]);
            },
        );

        return new HttpIndexService(
            new GenAiHttpClient($httpClient, 'https://genai.example.com'),
            $this->createResourceChannel(),
            new GenAiDocumentFactory(),
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
            $this->createMock(ResourceTenant::class),
        );
    }

    public function testGetManagedIndicesWithoutIndicesKey(): void
    {
        $service = $this->createService('{}');

        $this->assertEquals(
            [],
            $service->getManagedIndices(),
            'a response without indices should yield none',
        );
    }
}
