<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Console\Command;

use Atoolo\GenAi\Assistant;
use Atoolo\GenAi\Console\Command\Ask;
use Atoolo\GenAi\Dto\Assistant\Answer;
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
            'The answer is 42.',
            [new AnswerSource('123', '/a.php', 'A', 0.75)],
            'c-1',
            0.5,
        ));

        $tester->execute(['question' => 'why?']);
        $tester->assertCommandIsSuccessful();

        $output = $tester->getDisplay();
        $this->assertStringContainsString(
            'The answer is 42.',
            $output,
            'the answer should be printed',
        );
        $this->assertStringContainsString(
            '/a.php',
            $output,
            'the sources should be printed',
        );
    }

    public function testExecuteWithLanguage(): void
    {
        $tester = $this->createTester(new Answer('42'));

        $tester->execute(['question' => 'why?', '--lang' => 'en_US']);
        $tester->assertCommandIsSuccessful();

        $this->assertEquals(
            'en',
            $this->askedQuestion?->lang->code,
            'the language option should reach the assistant',
        );
    }

    public function testExecuteWithoutSources(): void
    {
        $tester = $this->createTester(new Answer('42'));

        $tester->execute(['question' => 'why?']);
        $tester->assertCommandIsSuccessful();

        $this->assertStringNotContainsString(
            'Sources',
            $tester->getDisplay(),
            'without sources no source table should be printed',
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
