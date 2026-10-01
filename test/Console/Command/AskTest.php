<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Console\Command;

use Atoolo\GenAi\Assistant;
use Atoolo\GenAi\Console\Command\Ask;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerCutOffError;
use Atoolo\GenAi\Dto\Assistant\AnswerLink;
use Atoolo\GenAi\Dto\Assistant\AnswerLinksSection;
use Atoolo\GenAi\Dto\Assistant\AnswerSource;
use Atoolo\GenAi\Dto\Assistant\AnswerTextSection;
use Atoolo\GenAi\Dto\Assistant\NoMatchingDocumentsError;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Dto\Assistant\QuestionResult;
use Atoolo\GenAi\Dto\Assistant\UnansweredError;
use Atoolo\Index\Console\Application;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceTenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Ask::class)]
class AskTest extends TestCase
{
    private ?Question $askedQuestion = null;

    public function testExecute(): void
    {
        $tester = $this->createTester(new Answer(
            'a-1',
            't-1',
            [
                new AnswerTextSection(
                    'Opening hours',
                    '<p>The office is open on Monday.</p>',
                    [new AnswerSource('/a.php', 'A')],
                ),
                new AnswerLinksSection(
                    '',
                    [
                        new AnswerLink('/b.php', 'B'),
                        new AnswerLink('/c.php'),
                    ],
                ),
            ],
            0.5,
        ));

        $tester->execute(['question' => 'why?']);
        $tester->assertCommandIsSuccessful();

        $output = $tester->getDisplay();
        foreach (
            [
                'Opening hours',
                'The office is open on Monday.',
                '/a.php',
                'AnswerLinksSection',
                'B: /b.php',
                '/c.php',
                'answer id: a-1',
            ] as $expected
        ) {
            $this->assertStringContainsString(
                $expected,
                $output,
                'the answer should be printed',
            );
        }
    }

    public function testExecuteWithNoMatchingDocuments(): void
    {
        $tester = $this->createTester(new NoMatchingDocumentsError(
            null,
            't-1',
            [new AnswerTextSection('', '<p>Ask more precisely.</p>')],
            ['When is the office open?'],
        ));

        $tester->execute(['question' => 'why?']);
        $tester->assertCommandIsSuccessful();

        $output = $tester->getDisplay();
        foreach (
            [
                'Not answered: NoMatchingDocumentsError',
                'Ask more precisely.',
                'Did you mean:',
                'When is the office open?',
            ] as $expected
        ) {
            $this->assertStringContainsString(
                $expected,
                $output,
                'the error, its hints and suggested questions should be '
                . 'printed',
            );
        }
        $this->assertStringNotContainsString(
            'answer id',
            $output,
            'an unstored result has no id',
        );
    }

    public function testExecuteWithError(): void
    {
        $tester = $this->createTester(new AnswerCutOffError('a-1'));

        $tester->execute(['question' => 'why?']);
        $tester->assertCommandIsSuccessful();

        $output = $tester->getDisplay();
        $this->assertStringContainsString(
            'Not answered: AnswerCutOffError',
            $output,
            'the error should be printed with its type',
        );
        $this->assertStringNotContainsString(
            'Did you mean:',
            $output,
            'only a NoMatchingDocumentsError suggests questions',
        );
    }

    public function testExecuteWithUnknownError(): void
    {
        $tester = $this->createTester(
            new UnansweredError('a-1', 't-1', 'QuotaExceededError'),
        );

        $tester->execute(['question' => 'why?']);
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString(
            'Not answered: QuotaExceededError',
            $tester->getDisplay(),
            'an unknown error should be printed with the type name of the '
            . 'application',
        );
    }

    public function testExecuteWithUnnamedError(): void
    {
        $tester = $this->createTester(new UnansweredError());

        $tester->execute(['question' => 'why?']);

        $this->assertStringContainsString(
            'Not answered: UnansweredError',
            $tester->getDisplay(),
            'an error without a type name should be printed with its class',
        );
    }

    public function testExecuteWithLanguageAndCategories(): void
    {
        $tester = $this->createTester(new Answer());

        $tester->execute([
            'question' => 'why?',
            '--lang' => 'en_US',
            '--category' => ['10', '20'],
        ]);
        $tester->assertCommandIsSuccessful();

        $this->assertEquals(
            'en',
            $this->askedQuestion?->lang->code,
            'the language option should reach the assistant',
        );
        $this->assertEquals(
            ['10', '20'],
            $this->askedQuestion?->categoryIds,
            'the category options should reach the assistant',
        );
    }

    private function createTester(QuestionResult $answer): CommandTester
    {
        $assistant = $this->createStub(Assistant::class);
        $assistant->method('ask')
            ->willReturnCallback(function (Question $question) use ($answer) {
                $this->askedQuestion = $question;
                return $answer;
            });

        $command = new Ask(
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
            $assistant,
        );

        return new CommandTester(
            (new Application([$command]))->find('genai:ask'),
        );
    }
}
