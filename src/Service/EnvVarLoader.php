<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service;

use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

/**
 * Lets the GenAI application be configured by one url.
 *
 * The connection is held as scheme, host, port and path, the way the
 * search-bundle holds the Solr connection, so that each part can be set on
 * its own. An environment usually knows the application as a single address
 * though, so `GENAI_URL` is taken apart into those parts here. A part that is
 * set explicitly is left alone.
 */
class EnvVarLoader implements EnvVarLoaderInterface
{
    /**
     * @return array<string,string>
     */
    public function loadEnvVars(): array
    {
        $genAiUrl = $_SERVER['GENAI_URL'] ?? '';
        if (!is_string($genAiUrl) || $genAiUrl === '') {
            return [];
        }

        $url = parse_url($genAiUrl);
        if ($url === false) {
            return [];
        }

        $scheme = $url['scheme'] ?? 'http';

        return [
            'GENAI_SCHEME' => $scheme,
            'GENAI_HOST' => $url['host'] ?? 'localhost',
            'GENAI_PORT' => (string) (
                $url['port'] ?? ($scheme === 'https' ? 443 : 80)
            ),
            'GENAI_PATH' => rtrim($url['path'] ?? '', '/'),
        ];
    }
}
