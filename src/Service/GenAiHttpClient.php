<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service;

use Atoolo\GenAi\Exception\GenAiGraphQlException;
use Atoolo\GenAi\Exception\GenAiRequestException;
use JsonException;
use Symfony\Component\HttpClient\Exception\JsonException as HttpJsonException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the GenAI application. Every response is turned into an array,
 * every failure into a {@see GenAiRequestException}, so that no transport
 * detail leaks into the services above.
 *
 * The client prepends nothing but the base url. The GenAI application serves
 * the indexing API under `/api`, its health under `/actuator/health` and
 * answers questions under `/graphql`, so a common prefix would only be in the
 * way; the caller names the full path.
 *
 * A GraphQL operation is sent on behalf of the user of the current request,
 * so it carries the client ip in `X-Forwarded-For`, which lets the
 * application limit requests per ip. Only the ip Symfony resolved is sent,
 * honouring the trusted proxies; a chain the caller sent along could be
 * forged and is not passed on.
 */
class GenAiHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $apiKey = '',
        private readonly ?RequestStack $requestStack = null,
    ) {}

    /**
     * @param array<string,mixed>|list<mixed>|null $json
     * @param array<string,string> $headers
     * @return array<string,mixed>
     * @throws GenAiRequestException
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
    ): array {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        $options = ['headers' => array_merge($this->headers(), $headers)];
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                throw new GenAiRequestException(
                    $method . ' ' . $url . ' failed with status '
                    . $statusCode,
                    $statusCode,
                );
            }
            if ($statusCode === 204) {
                return [];
            }
            $content = $response->getContent();
            if (trim($content) === '') {
                return [];
            }
            /** @var array<string,mixed> $data */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return $data;
        } catch (GenAiRequestException $e) {
            throw $e;
        } catch (JsonException|HttpJsonException $e) {
            throw new GenAiRequestException(
                $method . ' ' . $url . ' returned invalid JSON: '
                . $e->getMessage(),
                0,
                $e,
            );
        } catch (ExceptionInterface $e) {
            throw new GenAiRequestException(
                $method . ' ' . $url . ' failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Sends a GraphQL operation to `/graphql` and returns its `data`.
     *
     * GraphQL reports a failed operation with status 200 and a list of
     * `errors`, which would otherwise go unnoticed; it becomes a
     * {@see GenAiGraphQlException} that keeps the classification and the
     * message of the first error.
     *
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     * @throws GenAiRequestException
     */
    public function graphql(string $query, array $variables = []): array
    {
        $payload = ['query' => $query];
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $headers = [];
        $clientIp = $this->requestStack?->getMainRequest()?->getClientIp();
        if ($clientIp !== null) {
            $headers['X-Forwarded-For'] = $clientIp;
        }

        $response = $this->request('POST', 'graphql', $payload, $headers);

        $errors = $response['errors'] ?? null;
        if (is_array($errors) && !empty($errors)) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[] = is_array($error)
                    && is_string($error['message'] ?? null)
                    ? $error['message']
                    : 'unknown error';
            }
            $first = reset($errors);
            $classification = is_array($first)
                && is_array($first['extensions'] ?? null)
                && is_string($first['extensions']['classification'] ?? null)
                ? $first['extensions']['classification']
                : null;
            throw new GenAiGraphQlException(
                'POST graphql failed: ' . implode('; ', $messages),
                $classification,
                $messages[0],
            );
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new GenAiRequestException('POST graphql returned no data');
        }
        /** @var array<string,mixed> $data */
        return $data;
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($this->apiKey !== '') {
            $headers['X-API-Key'] = $this->apiKey;
        }
        return $headers;
    }
}
