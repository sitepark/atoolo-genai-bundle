<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\GraphQL;

use Atoolo\GenAi\Assistant as GenAiAssistant;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerFeedback;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Exception\AssistantErrorType;
use Atoolo\GenAi\Exception\AssistantException;
use Atoolo\GenAi\GraphQL\Assistant;
use Atoolo\GenAi\GraphQL\AssistantError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Assistant::class)]
#[CoversClass(AssistantError::class)]
class AssistantTest extends TestCase
{
    public function testQuestion(): void
    {
        $answer = new Answer('a-1');
        $genAiAssistant = $this->createMock(GenAiAssistant::class);
        $genAiAssistant->expects($this->once())
            ->method('ask')
            ->with($this->callback(
                static fn(Question $question) => $question->text === 'why?'
                    && $question->lang->code === 'en'
                    && $question->categoryIds === ['10'],
            ))
            ->willReturn($answer);

        $this->assertSame(
            $answer,
            (new Assistant($genAiAssistant))->question('why?', 'en_US', ['10']),
            'the answer of the assistant should be returned',
        );
    }

    public function testQuestionWithoutOptionalArguments(): void
    {
        $genAiAssistant = $this->createMock(GenAiAssistant::class);
        $genAiAssistant->expects($this->once())
            ->method('ask')
            ->with($this->callback(
                static fn(Question $question) => $question->lang->code === ''
                    && $question->categoryIds === [],
            ))
            ->willReturn(new Answer());

        (new Assistant($genAiAssistant))->question('why?');
    }

    public function testAnswerFeedback(): void
    {
        $genAiAssistant = $this->createMock(GenAiAssistant::class);
        $genAiAssistant->expects($this->once())
            ->method('feedback')
            ->with('a-1', 't-1', AnswerFeedback::GOOD)
            ->willReturn(true);

        $this->assertTrue(
            (new Assistant($genAiAssistant))
                ->answerFeedback('a-1', 't-1', AnswerFeedback::GOOD),
            'the result of the assistant should be returned',
        );
    }

    public function testErrorForTheCallerKeepsItsReason(): void
    {
        $genAiAssistant = $this->createStub(GenAiAssistant::class);
        $genAiAssistant->method('ask')->willThrowException(
            new AssistantException(
                'Too many questions',
                AssistantErrorType::TOO_MANY_REQUESTS,
            ),
        );
        try {
            (new Assistant($genAiAssistant))->question('why?');
            $this->fail('the error should be passed on');
        } catch (AssistantError $e) {
            $this->assertEquals(
                [
                    'Too many questions',
                    ['classification' => 'TOO_MANY_REQUESTS'],
                    true,
                    null,
                ],
                [
                    $e->getMessage(),
                    $e->getExtensions(),
                    $e->isClientSafe(),
                    $e->getPrevious(),
                ],
                'the reason and classification should reach the client, '
                . 'without a previous exception for the error log',
            );
        }
    }

    public function testInternalErrorIsHidden(): void
    {
        $genAiAssistant = $this->createStub(GenAiAssistant::class);
        $cause = new AssistantException(
            'POST https://genai.internal/graphql failed with status 500',
        );
        $genAiAssistant->method('feedback')->willThrowException($cause);

        try {
            (new Assistant($genAiAssistant))
                ->answerFeedback('a-1', 't-1', AnswerFeedback::BAD);
            $this->fail('the error should be passed on');
        } catch (AssistantError $e) {
            $this->assertEquals(
                [
                    'The GenAI application is not available',
                    ['classification' => 'INTERNAL_ERROR'],
                    $cause,
                ],
                [$e->getMessage(), $e->getExtensions(), $e->getPrevious()],
                'the address of the application must not reach the client, '
                . 'but the cause should be kept for the error log',
            );
        }
    }
}
