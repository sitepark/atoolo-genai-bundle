<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Assistant;

use Atoolo\GenAi\Assistant;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerError;
use Atoolo\GenAi\Dto\Assistant\AnswerFeedback;
use Atoolo\GenAi\Dto\Assistant\AnswerLink;
use Atoolo\GenAi\Dto\Assistant\AnswerSection;
use Atoolo\GenAi\Dto\Assistant\AnswerSectionType;
use Atoolo\GenAi\Dto\Assistant\AnswerSource;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Exception\AssistantException;
use Atoolo\GenAi\Exception\GenAiRequestException;
use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;

/**
 * Asks the GenAI application a question and passes on the feedback on an
 * answer, both through its GraphQL API.
 *
 * Only these fixed operations are sent, never a query of the caller: the
 * client may carry an API key that grants far more than the public fields,
 * and the channel is the one of the {@see ResourceChannel}, not the
 * caller's choice.
 */
class HttpAssistant implements Assistant
{
    private const QUESTION = <<<'GRAPHQL'
        query Question(
          $query: String!
          $language: String!
          $channel: String!
          $categoryIds: [String!]
        ) {
          question(
            query: $query
            language: $language
            channel: $channel
            categoryIds: $categoryIds
          ) {
            id
            error
            sections {
              type
              headline
              html
              links { url label }
              sources { url title }
              questions
            }
          }
        }
        GRAPHQL;

    private const FEEDBACK = <<<'GRAPHQL'
        mutation AnswerFeedback($answerId: ID!, $feedback: AnswerFeedback) {
          answerFeedback(answerId: $answerId, feedback: $feedback)
        }
        GRAPHQL;

    public function __construct(
        private readonly GenAiHttpClient $client,
        private readonly ResourceChannel $resourceChannel,
    ) {}

    public function ask(Question $question): Answer
    {
        $start = microtime(true);

        $variables = [
            'query' => $question->text,
            'language' => $this->language($question->lang),
            'channel' => $this->resourceChannel->searchIndex,
        ];
        if (!empty($question->categoryIds)) {
            $variables['categoryIds'] = array_values($question->categoryIds);
        }

        try {
            $data = $this->client->graphql(self::QUESTION, $variables);
        } catch (GenAiRequestException $e) {
            throw new AssistantException(
                'Unable to ask the GenAI application: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $answer = is_array($data['question'] ?? null) ? $data['question'] : [];

        return new Answer(
            is_string($answer['id'] ?? null) ? $answer['id'] : null,
            $this->toSections($answer['sections'] ?? null),
            is_string($answer['error'] ?? null)
                ? AnswerError::tryFrom($answer['error'])
                : null,
            round(microtime(true) - $start, 3),
        );
    }

    public function feedback(string $answerId, ?AnswerFeedback $feedback): bool
    {
        try {
            $data = $this->client->graphql(self::FEEDBACK, [
                'answerId' => $answerId,
                'feedback' => $feedback?->value,
            ]);
        } catch (GenAiRequestException $e) {
            throw new AssistantException(
                'Unable to give feedback to the GenAI application: '
                . $e->getMessage(),
                0,
                $e,
            );
        }

        return ($data['answerFeedback'] ?? false) === true;
    }

    /**
     * The application requires a language; a question without one is taken
     * to be in the language of the channel.
     */
    private function language(ResourceLanguage $lang): string
    {
        return $lang->code !== ''
            ? $lang->code
            : ResourceLanguage::of($this->resourceChannel->locale)->code;
    }

    /**
     * @return AnswerSection[]
     */
    private function toSections(mixed $sections): array
    {
        if (!is_array($sections)) {
            return [];
        }

        $result = [];
        foreach ($sections as $section) {
            if (!is_array($section) || !is_string($section['type'] ?? null)) {
                continue;
            }
            $type = AnswerSectionType::tryFrom($section['type']);
            if ($type === null) {
                continue;
            }
            $result[] = new AnswerSection(
                $type,
                $this->string($section, 'headline'),
                $this->string($section, 'html'),
                $this->toLinks($section['links'] ?? null),
                $this->toSources($section['sources'] ?? null),
                $this->toQuestions($section['questions'] ?? null),
            );
        }
        return $result;
    }

    /**
     * @return AnswerLink[]
     */
    private function toLinks(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }

        $result = [];
        foreach ($links as $link) {
            if (!is_array($link) || !is_string($link['url'] ?? null)) {
                continue;
            }
            $result[] = new AnswerLink(
                $link['url'],
                $this->string($link, 'label'),
            );
        }
        return $result;
    }

    /**
     * @return AnswerSource[]
     */
    private function toSources(mixed $sources): array
    {
        if (!is_array($sources)) {
            return [];
        }

        $result = [];
        foreach ($sources as $source) {
            if (!is_array($source) || !is_string($source['url'] ?? null)) {
                continue;
            }
            $result[] = new AnswerSource(
                $source['url'],
                $this->string($source, 'title'),
            );
        }
        return $result;
    }

    /**
     * @return string[]
     */
    private function toQuestions(mixed $questions): array
    {
        if (!is_array($questions)) {
            return [];
        }
        return array_values(array_filter($questions, 'is_string'));
    }

    /**
     * @param array<mixed> $data
     */
    private function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
