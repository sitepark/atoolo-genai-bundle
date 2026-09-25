<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Assistant;

use Atoolo\GenAi\Dto\Assistant\AnswerError;
use Atoolo\GenAi\Dto\Assistant\AnswerFeedback;
use Atoolo\GenAi\Dto\Assistant\AnswerSectionType;
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
        $assistant = $this->createAssistant(json_encode(['data' => [
            'question' => [
                'id' => 'a-1',
                'error' => null,
                'sections' => [
                    [
                        'type' => 'TEXT',
                        'headline' => 'Opening hours',
                        'html' => '<p>Mo-Fr</p>',
                        'links' => [],
                        'sources' => [['url' => '/a.php', 'title' => 'A']],
                        'questions' => [],
                    ],
                    [
                        'type' => 'LINKS',
                        'headline' => 'More',
                        'html' => '',
                        'links' => [['url' => '/b.php', 'label' => 'B']],
                        'sources' => [],
                        'questions' => [],
                    ],
                ],
            ],
        ]], JSON_THROW_ON_ERROR));

        $answer = $assistant->ask(new Question('why?'));

        $this->assertEquals('a-1', $answer->id, 'unexpected answer id');
        $this->assertNull($answer->error, 'an answer should have no error');
        $this->assertCount(2, $answer->sections, 'unexpected section count');

        $text = $answer->sections[0];
        $this->assertEquals(
            AnswerSectionType::TEXT,
            $text->type,
            'unexpected section type',
        );
        $this->assertEquals('<p>Mo-Fr</p>', $text->html, 'unexpected html');
        $this->assertEquals(
            '/a.php',
            $text->sources[0]->url,
            'unexpected source url',
        );

        $links = $answer->sections[1];
        $this->assertEquals(
            AnswerSectionType::LINKS,
            $links->type,
            'unexpected section type',
        );
        $this->assertEquals('B', $links->links[0]->label, 'unexpected label');
    }

    public function testAskWithError(): void
    {
        $assistant = $this->createAssistant(json_encode(['data' => [
            'question' => [
                'id' => 'a-1',
                'error' => 'NO_MATCHING_DOCUMENTS',
                'sections' => [[
                    'type' => 'TEXT',
                    'headline' => '',
                    'html' => '<p>Ask more precisely.</p>',
                    'links' => [],
                    'sources' => [],
                    'questions' => ['When is the office open?', 42],
                ]],
            ],
        ]], JSON_THROW_ON_ERROR));

        $answer = $assistant->ask(new Question('why?'));

        $this->assertEquals(
            AnswerError::NO_MATCHING_DOCUMENTS,
            $answer->error,
            'unexpected error',
        );
        $this->assertEquals(
            ['When is the office open?'],
            $answer->sections[0]->questions,
            'only the questions that are strings should be kept',
        );
    }

    public function testAskUrlAndVariables(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');

        $assistant->ask(new Question(
            'why?',
            ResourceLanguage::of('en_US'),
            ['10'],
        ));

        $this->assertEquals(
            'POST',
            $this->requests[0]['method'],
            'unexpected method',
        );
        $this->assertEquals(
            'https://genai.example.com/graphql',
            $this->requests[0]['url'],
            'unexpected url',
        );
        $this->assertEquals(
            [
                'query' => 'why?',
                'language' => 'en',
                'channel' => 'www',
                'categoryIds' => ['10'],
            ],
            $this->variables(),
            'unexpected variables',
        );
    }

    public function testAskFallsBackToTheChannelLanguage(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');

        $assistant->ask(new Question('why?'));

        $this->assertEquals(
            ['query' => 'why?', 'language' => 'de', 'channel' => 'www'],
            $this->variables(),
            'without a language the one of the channel should be sent, '
            . 'without categories none',
        );
    }

    public function testAskSkipsIncompleteParts(): void
    {
        $assistant = $this->createAssistant(json_encode(['data' => [
            'question' => [
                'sections' => [
                    ['headline' => 'no type'],
                    ['type' => 'UNKNOWN'],
                    'no section',
                    [
                        'type' => 'LINKS',
                        'links' => [['label' => 'no url'], 'no link'],
                        'sources' => [['title' => 'no url'], 'no source'],
                        'questions' => 'no list',
                    ],
                ],
            ],
        ]], JSON_THROW_ON_ERROR));

        $answer = $assistant->ask(new Question('why?'));

        $this->assertNull($answer->id, 'an unstored answer has no id');
        $this->assertCount(
            1,
            $answer->sections,
            'sections without a known type should be skipped',
        );
        $this->assertEquals(
            [[], [], []],
            [
                $answer->sections[0]->links,
                $answer->sections[0]->sources,
                $answer->sections[0]->questions,
            ],
            'links and sources without an url should be skipped',
        );
    }

    public function testAskWithoutSections(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":null}}');

        $this->assertEquals(
            [],
            $assistant->ask(new Question('why?'))->sections,
            'an empty answer should have no sections',
        );
    }

    public function testRequestErrorBecomesAssistantException(): void
    {
        $assistant = $this->createAssistant('{}', 500);

        $this->expectException(AssistantException::class);
        $assistant->ask(new Question('why?'));
    }

    public function testGraphQlErrorBecomesAssistantException(): void
    {
        $assistant = $this->createAssistant(
            '{"errors":[{"message":"no documents in channel"}],"data":null}',
        );

        $this->expectException(AssistantException::class);
        $this->expectExceptionMessage('no documents in channel');
        $assistant->ask(new Question('why?'));
    }

    public function testFeedback(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"answerFeedback":true}}',
        );

        $this->assertTrue(
            $assistant->feedback('a-1', AnswerFeedback::GOOD),
            'a known answer should take the feedback',
        );
        $this->assertEquals(
            ['answerId' => 'a-1', 'feedback' => 'GOOD'],
            $this->variables(),
            'unexpected variables',
        );
    }

    public function testWithdrawFeedback(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"answerFeedback":true}}',
        );

        $assistant->feedback('a-1', null);

        $this->assertEquals(
            ['answerId' => 'a-1', 'feedback' => null],
            $this->variables(),
            'null should be sent to withdraw the feedback',
        );
    }

    public function testFeedbackForUnknownAnswer(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"answerFeedback":false}}',
        );

        $this->assertFalse(
            $assistant->feedback('a-1', AnswerFeedback::BAD),
            'an unknown answer should be reported',
        );
    }

    public function testFeedbackErrorBecomesAssistantException(): void
    {
        $assistant = $this->createAssistant('{}', 500);

        $this->expectException(AssistantException::class);
        $assistant->feedback('a-1', AnswerFeedback::BAD);
    }

    /**
     * @return array<string,mixed>
     */
    private function variables(): array
    {
        /** @var array{variables:array<string,mixed>} $body */
        $body = json_decode(
            $this->requests[0]['body'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        return $body['variables'];
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
                'de_DE',
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
