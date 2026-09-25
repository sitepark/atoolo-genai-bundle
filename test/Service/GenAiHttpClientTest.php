<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service;

use Atoolo\GenAi\Exception\GenAiGraphQlException;
use Atoolo\GenAi\Exception\GenAiRequestException;
use Atoolo\GenAi\Service\GenAiHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(GenAiHttpClient::class)]
class GenAiHttpClientTest extends TestCase
{
    public function testRequestUrlAndMethod(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{"status":"UP"}'),
            $requests,
        );

        $client->request('GET', 'actuator/health');

        $this->assertEquals(
            'GET',
            $requests[0]['method'],
            'unexpected method',
        );
        $this->assertEquals(
            'https://genai.example.com/actuator/health',
            $requests[0]['url'],
            'the client should prepend nothing but the base url',
        );
    }

    public function testRequestSendsJsonBody(): void
    {
        $requests = [];
        $client = $this->createClient(new MockResponse('{}'), $requests);

        $client->request('POST', 'api/index/purge', ['a' => 'b']);

        $this->assertEquals(
            '{"a":"b"}',
            $requests[0]['body'],
            'unexpected request body',
        );
    }

    public function testRequestSendsListBody(): void
    {
        $requests = [];
        $client = $this->createClient(new MockResponse('{}'), $requests);

        $client->request('POST', 'api/index/documents', [['id' => '1']]);

        $this->assertEquals(
            '[{"id":"1"}]',
            $requests[0]['body'],
            'a bulk is sent as a bare list, not wrapped in an object',
        );
    }

    public function testRequestSendsApiKeyHeader(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{}'),
            $requests,
            'secret',
        );

        $client->request('GET', 'actuator/health');

        $this->assertContains(
            'X-API-Key: secret',
            $requests[0]['headers'],
            'the api key should be sent as the X-API-Key header',
        );
    }

    public function testRequestWithoutApiKeySendsNoApiKeyHeader(): void
    {
        $requests = [];
        $client = $this->createClient(new MockResponse('{}'), $requests);

        $client->request('GET', 'actuator/health');

        $this->assertStringNotContainsString(
            'X-API-Key',
            implode(' ', $requests[0]['headers']),
            'without an api key no key header should be sent',
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
            $client->request('POST', 'api/index/documents/delete', []),
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
            $client->request('POST', 'api/index/purge', []),
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
            $client->request('GET', 'actuator/health');
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
        $client->request('GET', 'actuator/health');
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
        $client->request('GET', 'actuator/health');
    }

    /**
     * @param array<int,array{method:string,url:string,body:string,headers:string[]}> $requests
     */
    public function testGraphQlSendsQueryAndVariables(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{"data":{"roles":[]}}'),
            $requests,
        );

        $data = $client->graphql('query($a: Int) { roles }', ['a' => 1]);

        $this->assertEquals(
            'https://genai.example.com/graphql',
            $requests[0]['url'],
            'unexpected url',
        );
        $this->assertEquals(
            '{"query":"query($a: Int) { roles }","variables":{"a":1}}',
            $requests[0]['body'],
            'unexpected request body',
        );
        $this->assertEquals(['roles' => []], $data, 'unexpected data');
    }

    public function testGraphQlWithoutVariables(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{"data":{"roles":[]}}'),
            $requests,
        );

        $client->graphql('{ roles }');

        $this->assertEquals(
            '{"query":"{ roles }"}',
            $requests[0]['body'],
            'without variables none should be sent',
        );
    }

    public function testGraphQlErrorsThrow(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse(
                '{"errors":[{"message":"a"},{"no":"message"}],"data":null}',
            ),
            $requests,
        );

        $this->expectException(GenAiRequestException::class);
        $this->expectExceptionMessage('a; unknown error');
        $client->graphql('{ roles }');
    }

    public function testGraphQlErrorKeepsClassificationAndReason(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse(
                '{"errors":[{"message":"Too many questions",'
                . '"extensions":{"classification":"TOO_MANY_REQUESTS"}},'
                . '{"message":"second"}],"data":null}',
            ),
            $requests,
        );

        try {
            $client->graphql('{ roles }');
            $this->fail('a GraphQL error should throw');
        } catch (GenAiGraphQlException $e) {
            $this->assertEquals(
                ['TOO_MANY_REQUESTS', 'Too many questions'],
                [$e->classification, $e->reason],
                'the classification and message of the first error '
                . 'should be kept',
            );
        }
    }

    public function testGraphQlErrorWithoutClassification(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{"errors":["no object"]}'),
            $requests,
        );

        try {
            $client->graphql('{ roles }');
            $this->fail('a GraphQL error should throw');
        } catch (GenAiGraphQlException $e) {
            $this->assertEquals(
                [null, 'unknown error'],
                [$e->classification, $e->reason],
                'an error without classification should have none',
            );
        }
    }

    public function testGraphQlWithoutDataThrows(): void
    {
        $requests = [];
        $client = $this->createClient(new MockResponse('{}'), $requests);

        $this->expectException(GenAiRequestException::class);
        $client->graphql('{ roles }');
    }

    public function testGraphQlSendsClientIpAsForwardedFor(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create(
            '/api/graphql',
            'POST',
            server: [
                'REMOTE_ADDR' => '203.0.113.7',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
            ],
        ));

        $requests = [];
        $client = $this->createClient(
            new MockResponse('{"data":{"roles":[]}}'),
            $requests,
            '',
            $requestStack,
        );

        $client->graphql('{ roles }');

        $this->assertContains(
            'X-Forwarded-For: 203.0.113.7',
            $requests[0]['headers'],
            'the client ip should be sent, not a chain an untrusted '
            . 'caller sent along',
        );
    }

    public function testGraphQlWithoutRequestSendsNoForwardedFor(): void
    {
        $requests = [];
        $client = $this->createClient(
            new MockResponse('{"data":{"roles":[]}}'),
            $requests,
            '',
            new RequestStack(),
        );

        $client->graphql('{ roles }');

        $this->assertEmpty(
            preg_grep('/^X-Forwarded-For:/i', $requests[0]['headers']),
            'without a request, e.g. on the console, no ip can be sent',
        );
    }

    public function testRequestSendsNoForwardedFor(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', server: [
            'REMOTE_ADDR' => '203.0.113.7',
        ]));

        $requests = [];
        $client = $this->createClient(
            new MockResponse('{}'),
            $requests,
            '',
            $requestStack,
        );

        $client->request('POST', 'api/index/purge', []);

        $this->assertEmpty(
            preg_grep('/^X-Forwarded-For:/i', $requests[0]['headers']),
            'only GraphQL operations are sent on behalf of a user',
        );
    }

    private function createClient(
        MockResponse $response,
        array &$requests,
        string $apiKey = '',
        ?RequestStack $requestStack = null,
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
            $requestStack,
        );
    }

    public function testRequestWithEmptyBodyAndStatusOk(): void
    {
        $requests = [];
        $client = $this->createClient(new MockResponse(''), $requests);

        $this->assertEquals(
            [],
            $client->request('POST', 'api/index/purge', []),
            'an empty 200 body should produce an empty array',
        );
    }
}
