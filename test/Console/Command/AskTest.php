<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Console\Command;

use Atoolo\GenAi\Assistant;
use Atoolo\GenAi\Console\Command\Ask;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerError;
use Atoolo\GenAi\Dto\Assistant\AnswerLink;
use Atoolo\GenAi\Dto\Assistant\AnswerSection;
use Atoolo\GenAi\Dto\Assistant\AnswerSectionType;
use Atoolo\GenAi\Dto\Assistant\AnswerSource;
use Atoolo\GenAi\Dto\Assistant\Question;
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
                new AnswerSection(
                    AnswerSectionType::TEXT,
                    'Opening hours',
                    '<p>The office is open on Monday.</p>',
                    [],
                    [new AnswerSource('/a.php', 'A')],
                ),
                new AnswerSection(
                    AnswerSectionType::LINKS,
                    '',
                    '',
                    [
                        new AnswerLink('/b.php', 'B'),
                        new AnswerLink('/c.php'),
                    ],
                ),
            ],
            null,
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
                'LINKS',
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

    public function testExecuteWithError(): void
    {
        $tester = $this->createTester(new Answer(
            null,
            null,
            [new AnswerSection(
                AnswerSectionType::TEXT,
                '',
                '<p>Ask more precisely.</p>',
                [],
                [],
                ['When is the office open?'],
            )],
            AnswerError::NO_DOCUMENTS,
        ));

        $tester->execute(['question' => 'why?']);
        $tester->assertCommandIsSuccessful();

        $output = $tester->getDisplay();
        $this->assertStringContainsString(
            'NO_DOCUMENTS',
            $output,
            'the error should be printed',
        );
        $this->assertStringContainsString(
            'When is the office open?',
            $output,
            'the suggested questions should be printed',
        );
        $this->assertStringNotContainsString(
            'answer id',
            $output,
            'an unstored answer has no id',
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

    private function createTester(Answer $answer): CommandTester
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
