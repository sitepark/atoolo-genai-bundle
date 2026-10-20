<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Assistant;

use Atoolo\GenAi\Dto\Assistant\AnswerSourceKind;
use Atoolo\GenAi\Dto\Assistant\AnswerSource;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerCutOffError;
use Atoolo\GenAi\Dto\Assistant\AnswerFeedback;
use Atoolo\GenAi\Dto\Assistant\AnswerLinksSection;
use Atoolo\GenAi\Dto\Assistant\AnswerTextSection;
use Atoolo\GenAi\Dto\Assistant\Filter\CategoryFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\NotFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\ObjectTypeFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\OrFilter;
use Atoolo\GenAi\Dto\Assistant\NoDocumentsError;
use Atoolo\GenAi\Dto\Assistant\NoMatchingDocumentsError;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Dto\Assistant\QuestionResult;
use Atoolo\GenAi\Dto\Assistant\UnansweredError;
use Atoolo\GenAi\Exception\AssistantErrorType;
use Atoolo\GenAi\Exception\AssistantException;
use Atoolo\GenAi\Service\Assistant\HttpAssistant;
use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceTenant;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
                '__typename' => 'Answer',
                'id' => 'a-1',
                'feedbackToken' => 't-1',
                'sections' => [
                    [
                        '__typename' => 'TextSection',
                        'headline' => 'Opening hours',
                        'html' => '<p>Mo-Fr</p>',
                        'sources' => [['url' => '/a.php', 'title' => 'A']],
                    ],
                    [
                        '__typename' => 'LinksSection',
                        'headline' => 'More',
                        'links' => [['url' => '/b.php', 'label' => 'B']],
                        'sources' => [],
                    ],
                ],
            ],
        ]], JSON_THROW_ON_ERROR));

        $answer = $assistant->ask(new Question('why?'));

        $this->assertInstanceOf(Answer::class, $answer, 'expected an answer');
        $this->assertEquals('a-1', $answer->id, 'unexpected answer id');
        $this->assertCount(2, $answer->sections, 'unexpected section count');

        $text = $answer->sections[0];
        $this->assertInstanceOf(
            AnswerTextSection::class,
            $text,
            'unexpected section type',
        );
        $this->assertEquals(
            ['Opening hours', '<p>Mo-Fr</p>', '/a.php', 'A'],
            [
                $text->headline,
                $text->html,
                $text->sources[0]->url,
                $text->sources[0]->title,
            ],
            'unexpected text section',
        );

        $links = $answer->sections[1];
        $this->assertInstanceOf(
            AnswerLinksSection::class,
            $links,
            'unexpected section type',
        );
        $this->assertEquals(
            ['More', '/b.php', 'B'],
            [$links->headline, $links->links[0]->url, $links->links[0]->label],
            'unexpected links section',
        );
    }

    /**
     * @return iterable<string,array{string,class-string<QuestionResult>}>
     */
    public static function resultTypes(): iterable
    {
        yield 'answer' => ['Answer', Answer::class];
        yield 'no documents' => ['NoDocumentsError', NoDocumentsError::class];
        yield 'no matching documents' => [
            'NoMatchingDocumentsError',
            NoMatchingDocumentsError::class,
        ];
        yield 'cut off' => ['AnswerCutOffError', AnswerCutOffError::class];
        yield 'unknown' => ['QuotaExceededError', UnansweredError::class];
    }

    /**
     * @param class-string<QuestionResult> $expectedClass
     */
    #[DataProvider('resultTypes')]
    public function testAskMapsTheResultByItsType(
        string $typeName,
        string $expectedClass,
    ): void {
        $assistant = $this->createAssistant(json_encode(['data' => [
            'question' => [
                '__typename' => $typeName,
                'id' => 'a-1',
                'feedbackToken' => 't-1',
            ],
        ]], JSON_THROW_ON_ERROR));

        $result = $assistant->ask(new Question('why?'));

        $this->assertInstanceOf(
            $expectedClass,
            $result,
            'unexpected result type',
        );
        $this->assertEquals(
            ['a-1', 't-1'],
            [$result->id, $result->feedbackToken],
            'every result should keep its id and feedback token, so that '
            . 'an error can be rated as well',
        );
    }

    public function testAskWithAnUnknownError(): void
    {
        $assistant = $this->createAssistant(json_encode(['data' => [
            'question' => ['__typename' => 'QuotaExceededError'],
        ]], JSON_THROW_ON_ERROR));

        $result = $assistant->ask(new Question('why?'));

        $this->assertInstanceOf(
            UnansweredError::class,
            $result,
            'an unknown error should not fail',
        );
        $this->assertEquals(
            'QuotaExceededError',
            $result->typeName,
            'the type name of the application should be kept',
        );
    }

    public function testAskWithNoMatchingDocuments(): void
    {
        $assistant = $this->createAssistant(json_encode(['data' => [
            'question' => [
                '__typename' => 'NoMatchingDocumentsError',
                'id' => 'a-1',
                'feedbackToken' => 't-1',
                'hints' => [
                    [
                        'headline' => 'Hint',
                        'html' => '<p>Ask more precisely.</p>',
                        'sources' => [['url' => '/a.php', 'title' => 'A']],
                    ],
                    'no hint',
                ],
                'suggestedQuestions' => ['When is the office open?', 42],
            ],
        ]], JSON_THROW_ON_ERROR));

        $result = $assistant->ask(new Question('why?'));

        $this->assertInstanceOf(
            NoMatchingDocumentsError::class,
            $result,
            'unexpected result type',
        );
        $this->assertEquals(
            ['Hint', '<p>Ask more precisely.</p>', '/a.php'],
            [
                $result->hints[0]->headline,
                $result->hints[0]->html,
                $result->hints[0]->sources[0]->url,
            ],
            'the hints should be taken as text sections',
        );
        $this->assertCount(1, $result->hints, 'invalid hints are skipped');
        $this->assertEquals(
            ['When is the office open?'],
            $result->suggestedQuestions,
            'only the questions that are strings should be kept',
        );
    }

    public function testAskSendsTheTypeName(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');

        $assistant->ask(new Question('why?'));

        $body = $this->requests[0]['body'];
        foreach (
            [
                '__typename',
                '... on AnsweredQuestion',
                '... on LinksSection',
                'suggestedQuestions',
            ] as $expected
        ) {
            $this->assertStringContainsString(
                $expected,
                $body,
                'the result should be asked for by its type',
            );
        }
    }

    public function testAskUrlAndVariables(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');

        $assistant->ask(new Question(
            'why?',
            ResourceLanguage::of('en_US'),
            ['10'],
            true,
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
                'archive' => true,
            ],
            $this->variables(),
            'unexpected variables',
        );
    }

    public function testAskSendsTheFilter(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');

        $assistant->ask(new Question('why?', filter: [
            new NotFilter(new ObjectTypeFilter(['news'])),
            new OrFilter([
                new CategoryFilter(['3']),
                new ObjectTypeFilter(['media']),
            ]),
        ]));

        $this->assertEquals(
            [
                ['not' => ['objectTypes' => ['news']]],
                ['or' => [
                    ['categoryIds' => ['3']],
                    ['objectTypes' => ['media']],
                ]],
            ],
            $this->variables()['filter'] ?? null,
            'the filter should be sent as the application expects it',
        );
    }

    public function testAskWithoutFilterSendsTheDefaultFilter(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"question":{}}}',
            defaultFilter: [['not' => ['objectTypes' => ['news', 'notice']]]],
        );

        $assistant->ask(new Question('why?'));

        $this->assertEquals(
            [['not' => ['objectTypes' => ['news', 'notice']]]],
            $this->variables()['filter'] ?? null,
            'a question without filter should get the default filter',
        );
    }

    public function testAFilterOfTheQuestionReplacesTheDefault(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"question":{}}}',
            defaultFilter: [['not' => ['objectTypes' => ['news']]]],
        );

        $assistant->ask(new Question('why?', filter: [
            new CategoryFilter(['3']),
        ]));

        $this->assertEquals(
            [['categoryIds' => ['3']]],
            $this->variables()['filter'] ?? null,
            'any filter of the question should replace the default filter',
        );
    }

    public function testAnEmptyFilterAsksWithoutTheDefault(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"question":{}}}',
            defaultFilter: [['not' => ['objectTypes' => ['news']]]],
        );

        $assistant->ask(new Question('why?', filter: []));

        $this->assertSame(
            [],
            $this->variables()['filter'] ?? null,
            'an empty filter should be sent, so that neither default applies',
        );
    }

    public function testWithoutAnyDefaultTheFilterIsLeftToTheApplication(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');

        $assistant->ask(new Question('why?'));

        $this->assertArrayNotHasKey(
            'filter',
            $this->variables(),
            'the default filter of the channel should apply',
        );
    }

    public function testInvalidDefaultFilter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('atoolo_genai.assistant.default_filter');

        $this->createAssistant(
            '{"data":{"question":{}}}',
            defaultFilter: [['objectTypes' => ['news'], 'not' => []]],
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
            . 'without categories and archive none',
        );
    }

    public function testAskSendsTheQuestionWithoutTheWhitespaceAroundIt(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');

        $assistant->ask(new Question(" \n\twhy?\u{3000} "));

        $this->assertSame(
            'why?',
            $this->variables()['query'] ?? null,
            'the application keeps the question stripped, so it should be '
            . 'sent so',
        );
    }

    public function testAskRefusesATooLongQuestionWithoutAskingTheApplication(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');

        try {
            $assistant->ask(new Question(
                str_repeat('a', Question::MAX_LENGTH + 1),
            ));
            $this->fail('a too long question should throw');
        } catch (AssistantException $e) {
            $this->assertEquals(
                [AssistantErrorType::BAD_REQUEST, []],
                [$e->type, $this->requests],
                'a too long question should be refused as the application '
                . 'would, without a request that counts to its rate limit',
            );
        }
    }

    public function testAskCountsTheLengthAsTheApplicationDoes(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');
        $padded = str_repeat(' ', 50) . str_repeat('ä', Question::MAX_LENGTH)
            . str_repeat(' ', 50);

        $assistant->ask(new Question($padded));

        $this->assertCount(
            1,
            $this->requests,
            'umlauts count once and the whitespace around not at all',
        );
    }

    public function testAskCountsACharacterBeyondTheBasicPlaneTwice(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":{}}}');

        $this->expectException(AssistantException::class);
        $assistant->ask(new Question(
            str_repeat("\u{1F600}", Question::MAX_LENGTH / 2 + 1),
        ));
    }

    public function testAskSkipsIncompleteParts(): void
    {
        $assistant = $this->createAssistant(json_encode(['data' => [
            'question' => [
                '__typename' => 'Answer',
                'sections' => [
                    ['headline' => 'no type'],
                    ['__typename' => 'TableSection', 'headline' => 'unknown'],
                    'no section',
                    [
                        '__typename' => 'LinksSection',
                        'links' => [['label' => 'no url'], 'no link'],
                        'sources' => [['title' => 'no url'], 'no source'],
                    ],
                    ['__typename' => 'LinksSection'],
                ],
            ],
        ]], JSON_THROW_ON_ERROR));

        $answer = $assistant->ask(new Question('why?'));

        $this->assertInstanceOf(Answer::class, $answer, 'expected an answer');
        $this->assertNull($answer->id, 'an unstored answer has no id');
        $this->assertCount(
            2,
            $answer->sections,
            'sections without a known type should be skipped',
        );
        foreach ($answer->sections as $links) {
            $this->assertInstanceOf(
                AnswerLinksSection::class,
                $links,
                'unexpected section type',
            );
            $this->assertEquals(
                [[], []],
                [$links->links, $links->sources],
                'missing links and sources, and those without an url, '
                . 'should be skipped',
            );
        }
    }

    public function testAskKeepsTheInternalKnowledgeBaseWithoutUrl(): void
    {
        $assistant = $this->createAssistant(json_encode(['data' => [
            'question' => [
                '__typename' => 'Answer',
                'sections' => [
                    [
                        '__typename' => 'TextSection',
                        'headline' => 'Citizens\' offices',
                        'html' => '<p>Every office serves everyone.</p>',
                        'sources' => [
                            [
                                'kind' => 'KNOWLEDGE',
                                'url' => null,
                                'title' => 'Interne Wissensbasis',
                            ],
                            [
                                'kind' => 'DOCUMENT',
                                'url' => 'https://www.example.com/a.php',
                                'title' => 'A',
                            ],
                            ['url' => 'https://www.example.com/b.php', 'title' => 'B'],
                        ],
                    ],
                ],
            ],
        ]], JSON_THROW_ON_ERROR));

        $answer = $assistant->ask(new Question('who?'));

        $this->assertInstanceOf(Answer::class, $answer, 'expected an answer');
        $this->assertEquals(
            [
                new AnswerSource(
                    null,
                    'Interne Wissensbasis',
                    AnswerSourceKind::KNOWLEDGE,
                ),
                new AnswerSource('https://www.example.com/a.php', 'A'),
                new AnswerSource('https://www.example.com/b.php', 'B'),
            ],
            $answer->sections[0]->sources,
            'the internal knowledge base is kept without url, a source '
            . 'without kind is a document',
        );
    }

    public function testAskWithoutSections(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"question":{"__typename":"Answer"}}}',
        );

        $answer = $assistant->ask(new Question('why?'));

        $this->assertInstanceOf(Answer::class, $answer, 'expected an answer');
        $this->assertEquals(
            [],
            $answer->sections,
            'an answer without sections should have none',
        );
    }

    public function testAskWithoutResult(): void
    {
        $assistant = $this->createAssistant('{"data":{"question":null}}');

        $result = $assistant->ask(new Question('why?'));

        $this->assertInstanceOf(
            UnansweredError::class,
            $result,
            'a missing result should not count as an answer',
        );
        $this->assertEquals('', $result->typeName, 'no type was named');
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

    /**
     * @return array<string,array{string,AssistantErrorType,string}>
     */
    public static function classifiedErrors(): array
    {
        return [
            'bad request' => [
                'BAD_REQUEST',
                AssistantErrorType::BAD_REQUEST,
                'The question is too long',
            ],
            'too many requests' => [
                'TOO_MANY_REQUESTS',
                AssistantErrorType::TOO_MANY_REQUESTS,
                'The question is too long',
            ],
            'service unavailable' => [
                'SERVICE_UNAVAILABLE',
                AssistantErrorType::SERVICE_UNAVAILABLE,
                'The question is too long',
            ],
            'unauthorized' => [
                'UNAUTHORIZED',
                AssistantErrorType::INTERNAL_ERROR,
                'Unable to ask the GenAI application: POST graphql failed: '
                . 'The question is too long',
            ],
        ];
    }

    #[DataProvider('classifiedErrors')]
    public function testClassifiedError(
        string $classification,
        AssistantErrorType $expectedType,
        string $expectedMessage,
    ): void {
        $assistant = $this->createAssistant(json_encode([
            'errors' => [[
                'message' => 'The question is too long',
                'extensions' => ['classification' => $classification],
            ]],
            'data' => null,
        ], JSON_THROW_ON_ERROR));

        try {
            $assistant->ask(new Question('why?'));
            $this->fail('a GraphQL error should throw');
        } catch (AssistantException $e) {
            $this->assertEquals(
                [$expectedType, $expectedMessage],
                [$e->type, $e->getMessage()],
                'only the errors meant for the caller should keep their '
                . 'type and the reason of the application',
            );
        }
    }

    /**
     * @return array<string,array{int,AssistantErrorType}>
     */
    public static function statusesOfTheCaller(): array
    {
        return [
            'too large' => [413, AssistantErrorType::BAD_REQUEST],
            'too many requests' => [429, AssistantErrorType::TOO_MANY_REQUESTS],
            'unavailable' => [503, AssistantErrorType::SERVICE_UNAVAILABLE],
        ];
    }

    #[DataProvider('statusesOfTheCaller')]
    public function testStatusOfTheCallerKeepsItsMeaning(
        int $status,
        AssistantErrorType $expectedType,
    ): void {
        $assistant = $this->createAssistant('', $status);

        try {
            $assistant->ask(new Question('why?'));
            $this->fail('a failed request should throw');
        } catch (AssistantException $e) {
            $this->assertEquals(
                $expectedType,
                $e->type,
                'a status the application or a proxy answers for the '
                . 'caller should keep its meaning instead of an internal '
                . 'error',
            );
        }
    }

    public function testRequestErrorIsAnInternalError(): void
    {
        $assistant = $this->createAssistant('{}', 500);

        try {
            $assistant->feedback('t-1', AnswerFeedback::GOOD);
            $this->fail('a failed request should throw');
        } catch (AssistantException $e) {
            $this->assertEquals(
                AssistantErrorType::INTERNAL_ERROR,
                $e->type,
                'a transport error is an internal error',
            );
        }
    }

    public function testFeedback(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"answerFeedback":true}}',
        );

        $this->assertTrue(
            $assistant->feedback('t-1', AnswerFeedback::GOOD),
            'a known answer should take the feedback',
        );
        $this->assertEquals(
            ['feedbackToken' => 't-1', 'feedback' => 'GOOD'],
            $this->variables(),
            'unexpected variables',
        );
    }

    public function testWithdrawFeedback(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"answerFeedback":true}}',
        );

        $assistant->feedback('t-1', null);

        $this->assertEquals(
            ['feedbackToken' => 't-1', 'feedback' => null],
            $this->variables(),
            'null should be sent to withdraw the feedback',
        );
    }

    public function testFeedbackWithAnUnknownOrExpiredToken(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"answerFeedback":false}}',
        );

        $this->assertFalse(
            $assistant->feedback('expired', AnswerFeedback::GOOD),
            'an unknown or expired token should be reported',
        );
    }

    public function testFeedbackSendsTheToken(): void
    {
        $assistant = $this->createAssistant(
            '{"data":{"answerFeedback":true}}',
        );

        $assistant->feedback('t-1', AnswerFeedback::GOOD);

        $body = $this->requests[0]['body'];
        $this->assertStringContainsString(
            'answerFeedback(',
            $body,
            'the feedback mutation should be sent',
        );
        $this->assertStringContainsString(
            'feedbackToken: $feedbackToken',
            $body,
            'the token should be passed to the mutation',
        );
        $this->assertStringNotContainsString(
            'answerId',
            $body,
            'the answer is known by its token alone',
        );
    }

    public function testAskTakesTheFeedbackToken(): void
    {
        $assistant = $this->createAssistant(json_encode(['data' => [
            'question' => [
                '__typename' => 'Answer',
                'id' => 'a-1',
                'feedbackToken' => 't-1',
                'sections' => [],
            ],
        ]], JSON_THROW_ON_ERROR));

        $answer = $assistant->ask(new Question('why?'));

        $this->assertEquals(
            't-1',
            $answer->feedbackToken,
            'the token should be taken from the answer',
        );
        $this->assertStringContainsString(
            'feedbackToken',
            $this->requests[0]['body'],
            'the token should be asked for',
        );
    }

    /**
     * @return iterable<string,array{array<string,mixed>}>
     */
    public static function answersWithoutFeedbackToken(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['feedbackToken' => null]];
    }

    /**
     * @param array<string,mixed> $token
     */
    #[DataProvider('answersWithoutFeedbackToken')]
    public function testAnAnswerWithoutTokenCannotBeRated(array $token): void
    {
        $assistant = $this->createAssistant(json_encode(
            ['data' => ['question' => [
                '__typename' => 'Answer',
                'id' => 'a-1',
                'sections' => [],
            ] + $token]],
            JSON_THROW_ON_ERROR,
        ));

        $this->assertNull(
            $assistant->ask(new Question('why?'))->feedbackToken,
            'without a token the answer cannot be rated',
        );
    }

    public function testFeedbackErrorBecomesAssistantException(): void
    {
        $assistant = $this->createAssistant('{}', 500);

        $this->expectException(AssistantException::class);
        $assistant->feedback('t-1', AnswerFeedback::BAD);
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

    /**
     * @param array<mixed> $defaultFilter
     */
    private function createAssistant(
        string $body,
        int $statusCode = 200,
        array $defaultFilter = [],
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
            $defaultFilter,
        );
    }
}
