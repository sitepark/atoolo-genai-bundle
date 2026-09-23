<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Assistant;

use Atoolo\GenAi\Assistant;
use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerSource;
use Atoolo\GenAi\Dto\Assistant\Question;
use Atoolo\GenAi\Exception\AssistantException;
use Atoolo\GenAi\Exception\GenAiRequestException;
use Atoolo\GenAi\Service\GenAiHttpClient;
use Atoolo\Resource\ResourceChannel;

/**
 * Asks the GenAI application a question.
 *
 * Still speaks the REST contract this bundle defined before the application
 * existed. The application answers questions through GraphQL instead, so this
 * service is yet to be moved over; the indexer already speaks the real API.
 */
class HttpAssistant implements Assistant
{
    public function __construct(
        private readonly GenAiHttpClient $client,
        private readonly ResourceChannel $resourceChannel,
    ) {}

    public function ask(Question $question): Answer
    {
        $start = microtime(true);

        $payload = ['question' => $question->text];
        if ($question->lang->code !== '') {
            $payload['language'] = $question->lang->code;
        }
        if ($question->conversationId !== null) {
            $payload['conversation_id'] = $question->conversationId;
        }
        if (!empty($question->categories)) {
            $payload['categories'] = array_values($question->categories);
        }

        try {
            $response = $this->client->request(
                'POST',
                'api/v1/indices/'
                . rawurlencode($this->resourceChannel->searchIndex)
                . '/ask',
                $payload,
            );
        } catch (GenAiRequestException $e) {
            throw new AssistantException(
                'Unable to ask the GenAI application: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return new Answer(
            is_string($response['answer'] ?? null) ? $response['answer'] : '',
            $this->toSources($response['sources'] ?? null),
            is_string($response['conversation_id'] ?? null)
                ? $response['conversation_id']
                : null,
            round(microtime(true) - $start, 3),
        );
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
        /** @var array<array<string,mixed>> $sources */
        foreach ($sources as $source) {
            if (!isset($source['id']) || !is_string($source['id'])) {
                continue;
            }
            $result[] = new AnswerSource(
                $source['id'],
                is_string($source['url'] ?? null) ? $source['url'] : '',
                is_string($source['title'] ?? null) ? $source['title'] : '',
                is_numeric($source['score'] ?? null)
                    ? (float) $source['score']
                    : null,
            );
        }
        return $result;
    }
}
