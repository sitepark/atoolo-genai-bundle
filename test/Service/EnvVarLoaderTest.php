<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service;

use Atoolo\GenAi\Service\EnvVarLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnvVarLoader::class)]
class EnvVarLoaderTest extends TestCase
{
    /**
     * @var array<string,mixed>
     */
    private array $server = [];

    public function setUp(): void
    {
        $this->server = $_SERVER;
    }

    public function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testWithoutUrlNothingIsLoaded(): void
    {
        unset($_SERVER['GENAI_URL']);

        $this->assertEquals(
            [],
            (new EnvVarLoader())->loadEnvVars(),
            'without a url the parts should be left alone',
        );
    }

    public function testEmptyUrlIsIgnored(): void
    {
        $_SERVER['GENAI_URL'] = '';

        $this->assertEquals(
            [],
            (new EnvVarLoader())->loadEnvVars(),
            'an empty url should be ignored',
        );
    }

    public function testUnparsableUrlIsIgnored(): void
    {
        $_SERVER['GENAI_URL'] = 'https://genai.example.com:port';

        $this->assertEquals(
            [],
            (new EnvVarLoader())->loadEnvVars(),
            'a url that cannot be read should leave the parts alone',
        );
    }

    public function testUrlIsTakenApart(): void
    {
        $_SERVER['GENAI_URL'] = 'http://genai.example.com:9090/genai/';

        $this->assertEquals(
            [
                'GENAI_SCHEME' => 'http',
                'GENAI_HOST' => 'genai.example.com',
                'GENAI_PORT' => '9090',
                'GENAI_PATH' => '/genai',
            ],
            (new EnvVarLoader())->loadEnvVars(),
            'unexpected connection parts',
        );
    }

    public function testHttpsWithoutPortUses443(): void
    {
        $_SERVER['GENAI_URL'] = 'https://genai.example.com';

        $this->assertEquals(
            [
                'GENAI_SCHEME' => 'https',
                'GENAI_HOST' => 'genai.example.com',
                'GENAI_PORT' => '443',
                'GENAI_PATH' => '',
            ],
            (new EnvVarLoader())->loadEnvVars(),
            'https without a port should use 443',
        );
    }

    public function testHttpWithoutPortUses80(): void
    {
        $_SERVER['GENAI_URL'] = 'http://genai.example.com';

        $this->assertEquals(
            '80',
            (new EnvVarLoader())->loadEnvVars()['GENAI_PORT'],
            'http without a port should use 80',
        );
    }
}
