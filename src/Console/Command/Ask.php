<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Console\Command;

use Atoolo\GenAi\Assistant;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerLinksSection;
use Atoolo\GenAi\Dto\Assistant\AnswerSection;
use Atoolo\GenAi\Dto\Assistant\AnswerTextSection;
use Atoolo\GenAi\Dto\Assistant\NoMatchingDocumentsError;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Dto\Assistant\QuestionResult;
use Atoolo\GenAi\Dto\Assistant\UnansweredError;
use Atoolo\Index\Console\Command\Io\TypifiedInput;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'genai:ask',
    description: 'Ask the GenAI application a question',
)]
class Ask extends Command
{
    public function __construct(
        private readonly ResourceChannel $channel,
        private readonly Assistant $assistant,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Command to ask the GenAI application a question')
            ->addArgument(
                'question',
                InputArgument::REQUIRED,
                'The question to ask.',
            )
            ->addOption(
                'lang',
                null,
                InputOption::VALUE_REQUIRED,
                'Language of the question, e.g. en',
                '',
            )
            ->addOption(
                'category',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Category id the retrieved documents are restricted to, '
                . 'may be given several times',
            )
        ;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $typedInput = new TypifiedInput($input);

        $io = new SymfonyStyle($input, $output);
        $io->title('Channel: ' . $this->channel->name);

        /** @var string[] $categoryIds */
        $categoryIds = $input->getOption('category');

        $result = $this->assistant->ask(new Question(
            $typedInput->getStringArgument('question'),
            ResourceLanguage::of($typedInput->getStringOption('lang')),
            $categoryIds,
        ));

        if ($result instanceof Answer) {
            foreach ($result->sections as $section) {
                $this->printSection($io, $section);
            }
        } else {
            $io->warning('Not answered: ' . $this->typeName($result));
        }

        if ($result instanceof NoMatchingDocumentsError) {
            foreach ($result->hints as $hint) {
                $this->printSection($io, $hint);
            }
            if (!empty($result->suggestedQuestions)) {
                $io->text('Did you mean:');
                $io->listing($result->suggestedQuestions);
            }
        }

        if ($result->id !== null) {
            $io->text('answer id: ' . $result->id);
        }
        $io->text(sprintf('time: %.3fs', $result->duration));

        return Command::SUCCESS;
    }

    private function typeName(QuestionResult $result): string
    {
        if ($result instanceof UnansweredError && $result->typeName !== '') {
            return $result->typeName;
        }
        return (new \ReflectionClass($result))->getShortName();
    }

    private function printSection(
        SymfonyStyle $io,
        AnswerSection $section,
    ): void {
        $io->section(
            $section->headline !== ''
                ? $section->headline
                : (new \ReflectionClass($section))->getShortName(),
        );

        if ($section instanceof AnswerTextSection && $section->html !== '') {
            $io->text(trim(strip_tags($section->html)));
        }

        if ($section instanceof AnswerLinksSection && !empty($section->links)) {
            $io->listing(array_map(
                static fn($link) => $link->label !== ''
                    ? $link->label . ': ' . $link->url
                    : $link->url,
                $section->links,
            ));
        }

        if (!empty($section->sources)) {
            $io->table(
                ['title', 'url'],
                array_map(
                    static fn($source) => [$source->title, $source->url],
                    $section->sources,
                ),
            );
        }
    }
}
