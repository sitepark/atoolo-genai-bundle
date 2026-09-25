<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Console\Command;

use Atoolo\GenAi\Assistant;
use Atoolo\GenAi\Dto\Assistant\AnswerSection;
use Atoolo\GenAi\Dto\Assistant\Question;
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

        $answer = $this->assistant->ask(new Question(
            $typedInput->getStringArgument('question'),
            ResourceLanguage::of($typedInput->getStringOption('lang')),
            $categoryIds,
        ));

        if ($answer->error !== null) {
            $io->warning('Not answered: ' . $answer->error->name);
        }

        foreach ($answer->sections as $section) {
            $this->printSection($io, $section);
        }

        if ($answer->id !== null) {
            $io->text('answer id: ' . $answer->id);
        }
        $io->text(sprintf('time: %.3fs', $answer->duration));

        return Command::SUCCESS;
    }

    private function printSection(
        SymfonyStyle $io,
        AnswerSection $section,
    ): void {
        $io->section(
            $section->headline !== ''
                ? $section->headline
                : $section->type->name,
        );

        if ($section->html !== '') {
            $io->text(trim(strip_tags($section->html)));
        }

        if (!empty($section->links)) {
            $io->listing(array_map(
                static fn($link) => $link->label !== ''
                    ? $link->label . ': ' . $link->url
                    : $link->url,
                $section->links,
            ));
        }

        if (!empty($section->questions)) {
            $io->text('Did you mean:');
            $io->listing($section->questions);
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
