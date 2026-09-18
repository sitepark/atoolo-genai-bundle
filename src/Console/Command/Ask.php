<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Console\Command;

use Atoolo\GenAi\Assistant;
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
        ;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $typedInput = new TypifiedInput($input);

        $io = new SymfonyStyle($input, $output);
        $io->title('Channel: ' . $this->channel->name);

        $answer = $this->assistant->ask(new Question(
            $typedInput->getStringArgument('question'),
            ResourceLanguage::of($typedInput->getStringOption('lang')),
        ));

        $io->section('Answer');
        $io->text($answer->text);

        if (!empty($answer->sources)) {
            $io->section('Sources');
            $rows = [];
            foreach ($answer->sources as $source) {
                $rows[] = [
                    $source->id,
                    $source->title,
                    $source->url,
                    $source->score === null
                        ? ''
                        : number_format($source->score, 3),
                ];
            }
            $io->table(['id', 'title', 'url', 'score'], $rows);
        }

        $io->text(sprintf('time: %.3fs', $answer->duration));

        return Command::SUCCESS;
    }
}
