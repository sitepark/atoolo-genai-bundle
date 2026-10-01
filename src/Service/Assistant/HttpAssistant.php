<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Assistant;

use Atoolo\GenAi\Assistant;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerCutOffError;
use Atoolo\GenAi\Dto\Assistant\AnswerFeedback;
use Atoolo\GenAi\Dto\Assistant\AnswerLink;
use Atoolo\GenAi\Dto\Assistant\AnswerLinksSection;
use Atoolo\GenAi\Dto\Assistant\AnswerSection;
use Atoolo\GenAi\Dto\Assistant\AnswerSource;
use Atoolo\GenAi\Dto\Assistant\AnswerTextSection;
use Atoolo\GenAi\Dto\Assistant\NoDocumentsError;
use Atoolo\GenAi\Dto\Assistant\NoMatchingDocumentsError;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Dto\Assistant\QuestionResult;
use Atoolo\GenAi\Dto\Assistant\UnansweredError;
use Atoolo\GenAi\Exception\AssistantErrorType;
use Atoolo\GenAi\Exception\AssistantException;
use Atoolo\GenAi\Exception\GenAiGraphQlException;
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
            __typename
            ... on AnsweredQuestion { id feedbackToken }
            ... on Answer {
              sections {
                __typename
                headline
                sources { url title }
                ... on TextSection { html }
                ... on LinksSection { links { url label } }
              }
            }
            ... on NoMatchingDocumentsError {
              hints { headline html sources { url title } }
              suggestedQuestions
            }
          }
        }
        GRAPHQL;

    private const FEEDBACK = <<<'GRAPHQL'
        mutation AnswerFeedback($feedbackToken: String!, $feedback: AnswerFeedback) {
          answerFeedback(feedbackToken: $feedbackToken, feedback: $feedback)
        }
        GRAPHQL;

    public function __construct(
        private readonly GenAiHttpClient $client,
        private readonly ResourceChannel $resourceChannel,
    ) {}

    public function ask(Question $question): QuestionResult
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
            throw $this->toAssistantException(
                'Unable to ask the GenAI application: ',
                $e,
            );
        }

        $result = is_array($data['question'] ?? null)
            ? $data['question']
            : [];
        $id = is_string($result['id'] ?? null) ? $result['id'] : null;
        $feedbackToken = is_string($result['feedbackToken'] ?? null)
            ? $result['feedbackToken']
            : null;
        $typeName = $this->string($result, '__typename');
        $duration = round(microtime(true) - $start, 3);

        return match ($typeName) {
            'Answer' => new Answer(
                $id,
                $feedbackToken,
                $this->toSections($result['sections'] ?? null),
                $duration,
            ),
            'NoDocumentsError' => new NoDocumentsError(
                $id,
                $feedbackToken,
                $duration,
            ),
            'NoMatchingDocumentsError' => new NoMatchingDocumentsError(
                $id,
                $feedbackToken,
                $this->toTextSections($result['hints'] ?? null),
                $this->toQuestions($result['suggestedQuestions'] ?? null),
                $duration,
            ),
            'AnswerCutOffError' => new AnswerCutOffError(
                $id,
                $feedbackToken,
                $duration,
            ),
            // the application may add errors; they are kept, not failed on
            default => new UnansweredError(
                $id,
                $feedbackToken,
                $typeName,
                $duration,
            ),
        };
    }

    /**
     * The token is a secret of the user who asked; it is only passed on,
     * never stored or logged.
     */
    public function feedback(
        string $feedbackToken,
        ?AnswerFeedback $feedback,
    ): bool {
        try {
            $data = $this->client->graphql(self::FEEDBACK, [
                'feedbackToken' => $feedbackToken,
                'feedback' => $feedback?->value,
            ]);
        } catch (GenAiRequestException $e) {
            throw $this->toAssistantException(
                'Unable to give feedback to the GenAI application: ',
                $e,
            );
        }

        return ($data['answerFeedback'] ?? false) === true;
    }

    /**
     * Keeps the errors the application hands to the caller - a question it
     * does not accept, too many questions - with its message; anything else
     * is an internal error whose message is meant for the log only.
     */
    private function toAssistantException(
        string $prefix,
        GenAiRequestException $e,
    ): AssistantException {
        if ($e instanceof GenAiGraphQlException) {
            $type = match ($e->classification) {
                AssistantErrorType::BAD_REQUEST->value
                    => AssistantErrorType::BAD_REQUEST,
                AssistantErrorType::TOO_MANY_REQUESTS->value
                    => AssistantErrorType::TOO_MANY_REQUESTS,
                default => null,
            };
            if ($type !== null) {
                return new AssistantException($e->reason, $type, $e);
            }
        }
        return new AssistantException(
            $prefix . $e->getMessage(),
            AssistantErrorType::INTERNAL_ERROR,
            $e,
        );
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
     * Sections of a type this bundle does not know are skipped.
     *
     * @return AnswerSection[]
     */
    private function toSections(mixed $sections): array
    {
        if (!is_array($sections)) {
            return [];
        }

        $result = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $result[] = match ($section['__typename'] ?? null) {
                'TextSection' => $this->toTextSection($section),
                'LinksSection' => new AnswerLinksSection(
                    $this->string($section, 'headline'),
                    $this->toLinks($section['links'] ?? null),
                    $this->toSources($section['sources'] ?? null),
                ),
                default => null,
            };
        }
        return array_values(array_filter($result));
    }

    /**
     * The hints are text sections by their type, so they carry no
     * `__typename`.
     *
     * @return AnswerTextSection[]
     */
    private function toTextSections(mixed $sections): array
    {
        if (!is_array($sections)) {
            return [];
        }

        $result = [];
        foreach ($sections as $section) {
            if (is_array($section)) {
                $result[] = $this->toTextSection($section);
            }
        }
        return $result;
    }

    /**
     * @param array<mixed> $section
     */
    private function toTextSection(array $section): AnswerTextSection
    {
        return new AnswerTextSection(
            $this->string($section, 'headline'),
            $this->string($section, 'html'),
            $this->toSources($section['sources'] ?? null),
        );
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
