<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service;

use Atoolo\GenAi\Exception\GenAiRequestException;
use Atoolo\GenAi\Service\GenAiHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(GenAiHttpClient::class)]
class GenAiHttpClientTest extends TestCase
{
    public function testRequestUrlAndMethod(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{"indices":[]}'),
            $requests,
        );

        $client->request('GET', 'indices');

        $this->assertEquals(
            'GET',
            $requests[0]['method'],
            'unexpected method',
        );
        $this->assertEquals(
            'https://genai.example.com/api/v1/indices',
            $requests[0]['url'],
            'unexpected url',
        );
    }

    public function testRequestSendsJsonBody(): void
    {
        $requests = [];
        $client = $this->createClient(new MockResponse('{}'), $requests);

        $client->request('POST', 'indices/www/commit', ['a' => 'b']);

        $this->assertEquals(
            '{"a":"b"}',
            $requests[0]['body'],
            'unexpected request body',
        );
    }

    public function testRequestSendsBearerToken(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{}'),
            $requests,
            'secret',
        );

        $client->request('GET', 'health');

        $this->assertContains(
            'Authorization: Bearer secret',
            $requests[0]['headers'],
            'the api key should be sent as a bearer token',
        );
    }

    public function testRequestWithoutApiKeySendsNoAuthorization(): void
    {
        $requests = [];
        $client = $this->createClient(new MockResponse('{}'), $requests);

        $client->request('GET', 'health');

        $this->assertStringNotContainsString(
            'Authorization',
            implode(' ', $requests[0]['headers']),
            'without an api key no authorization header should be sent',
        );
    }

    public function testRequestDecodesResponse(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{"deleted":3}'),
            $requests,
        );

        $this->assertEquals(
            ['deleted' => 3],
            $client->request('POST', 'indices/www/documents/delete', []),
            'unexpected response',
        );
    }

    public function testRequestWithEmptyResponse(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('', ['http_code' => 204]),
            $requests,
        );

        $this->assertEquals(
            [],
            $client->request('POST', 'indices/www/commit', []),
            'a 204 should produce an empty array',
        );
    }

    public function testErrorStatusThrows(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{"message":"boom"}', ['http_code' => 500]),
            $requests,
        );

        try {
            $client->request('GET', 'health');
            $this->fail('a 500 should throw');
        } catch (GenAiRequestException $e) {
            $this->assertEquals(
                500,
                $e->getStatusCode(),
                'the status code should be kept',
            );
        }
    }

    public function testInvalidJsonThrows(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('not json'),
            $requests,
        );

        $this->expectException(GenAiRequestException::class);
        $client->request('GET', 'health');
    }

    public function testTransportErrorIsWrapped(): void
    {
        $client = new GenAiHttpClient(
            new MockHttpClient(
                static function (): MockResponse {
                    throw new TransportException('connection refused');
                },
            ),
            'https://genai.example.com',
        );

        $this->expectException(GenAiRequestException::class);
        $client->request('GET', 'health');
    }

    public function testEncodeIndex(): void
    {
        $this->assertEquals(
            'www%2Ftest',
            GenAiHttpClient::encodeIndex('www/test'),
            'the index name should be url encoded',
        );
    }

    /**
     * @param array<int,array{method:string,url:string,body:string,headers:string[]}> $requests
     */
    private function createClient(
        MockResponse $response,
        array &$requests,
        string $apiKey = '',
    ): GenAiHttpClient {
        $httpClient = new MockHttpClient(
            static function (
                string $method,
                string $url,
                array $options,
            ) use ($response, &$requests): MockResponse {
                $requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => (string) ($options['body'] ?? ''),
                    'headers' => $options['headers'] ?? [],
                ];
                return $response;
            },
        );

        return new GenAiHttpClient(
            $httpClient,
            'https://genai.example.com/',
            $apiKey,
        );
    }

    public function testRequestWithEmptyBodyAndStatusOk(): void
    {
        $requests = [];
        $client = $this->createClient(new MockResponse(''), $requests);

        $this->assertEquals(
            [],
            $client->request('POST', 'indices/www/commit', []),
            'an empty 200 body should produce an empty array',
        );
    }
}
