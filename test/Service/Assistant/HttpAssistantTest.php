<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Assistant;

use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Exception\AssistantException;
use Atoolo\GenAi\Service\Assistant\HttpAssistant;
use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceTenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpAssistant::class)]
class HttpAssistantTest extends TestCase
{
    /**
     * @var array<int,array{method:string,url:string,body:string}>
     */
    private array $requests = [];

    public function testAsk(): void
    {
        $assistant = $this->createAssistant(
            '{"answer":"42","conversation_id":"c-1",'
            . '"sources":[{"id":"123","url":"/a.php","title":"A",'
            . '"score":0.75}]}',
        );

        $answer = $assistant->ask(new Question('why?'));

        $this->assertEquals('42', $answer->text, 'unexpected answer');
        $this->assertEquals(
            'c-1',
            $answer->conversationId,
            'unexpected conversation id',
        );
        $this->assertCount(1, $answer->sources, 'unexpected source count');
        $this->assertEquals(
            '123',
            $answer->sources[0]->id,
            'unexpected source id',
        );
        $this->assertEquals(
            0.75,
            $answer->sources[0]->score,
            'unexpected source score',
        );
    }

    public function testAskUrlAndBody(): void
    {
        $assistant = $this->createAssistant('{"answer":"42"}');

        $assistant->ask(new Question(
            'why?',
            ResourceLanguage::of('en_US'),
            'c-1',
            ['10'],
        ));

        $this->assertEquals(
            'POST',
            $this->requests[0]['method'],
            'unexpected method',
        );
        $this->assertEquals(
            'https://genai.example.com/api/v1/indices/www/ask',
            $this->requests[0]['url'],
            'unexpected url',
        );
        $this->assertEquals(
            '{"question":"why?","language":"en","conversation_id":"c-1",'
            . '"categories":["10"]}',
            $this->requests[0]['body'],
            'unexpected body',
        );
    }

    public function testAskWithoutOptionalFields(): void
    {
        $assistant = $this->createAssistant('{"answer":"42"}');

        $assistant->ask(new Question('why?'));

        $this->assertEquals(
            '{"question":"why?"}',
            $this->requests[0]['body'],
            'empty options should be left out',
        );
    }

    public function testSourcesWithoutIdAreSkipped(): void
    {
        $assistant = $this->createAssistant(
            '{"answer":"42","sources":[{"url":"/a.php"}]}',
        );

        $this->assertCount(
            0,
            $assistant->ask(new Question('why?'))->sources,
            'a source without an id cannot be used',
        );
    }

    public function testRequestErrorBecomesAssistantException(): void
    {
        $assistant = $this->createAssistant('{}', 500);

        $this->expectException(AssistantException::class);
        $assistant->ask(new Question('why?'));
    }

    private function createAssistant(
        string $body,
        int $statusCode = 200,
    ): HttpAssistant {
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

        return new HttpAssistant(
            new GenAiHttpClient($httpClient, 'https://genai.example.com'),
            new ResourceChannel(
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
            ),
        );
    }
}
