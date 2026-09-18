<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service;

use Atoolo\GenAi\Exception\GenAiRequestException;
use JsonException;
use Symfony\Component\HttpClient\Exception\JsonException as HttpJsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the GenAI application. Every response is turned into an array,
 * every failure into a {@see GenAiRequestException}, so that no transport
 * detail leaks into the services above.
 */
class GenAiHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $apiKey = '',
    ) {}

    /**
     * @param array<string,mixed>|null $json
     * @return array<string,mixed>
     * @throws GenAiRequestException
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
    ): array {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/' . ltrim($path, '/');

        $options = ['headers' => $this->headers()];
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
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR | JSON_THROW_ON_ERROR);
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

    public static function encodeIndex(string $index): string
    {
        return rawurlencode($index);
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
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        return $headers;
    }
}
