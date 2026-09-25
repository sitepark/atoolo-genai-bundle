<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\GraphQL;

use Atoolo\GenAi\Assistant as GenAiAssistant;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerFeedback;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\GraphQL\Assistant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Assistant::class)]
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
            ->with('a-1', AnswerFeedback::GOOD)
            ->willReturn(true);

        $this->assertTrue(
            (new Assistant($genAiAssistant))
                ->answerFeedback('a-1', AnswerFeedback::GOOD),
            'the result of the assistant should be returned',
        );
    }
}
