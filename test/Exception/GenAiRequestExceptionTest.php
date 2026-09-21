<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Exception;

use Atoolo\GenAi\Exception\GenAiRequestException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenAiRequestException::class)]
class GenAiRequestExceptionTest extends TestCase
{
    public function testStatusCode(): void
    {
        $previous = new \RuntimeException('transport');
        $e = new GenAiRequestException('failed', 503, $previous);

        $this->assertEquals(503, $e->getStatusCode(), 'unexpected status');
        $this->assertEquals(503, $e->getCode(), 'code should mirror the status');
        $this->assertEquals('failed', $e->getMessage(), 'unexpected message');
        $this->assertSame($previous, $e->getPrevious(), 'unexpected previous');
    }

    public function testWithoutStatusCode(): void
    {
        $this->assertEquals(
            0,
            (new GenAiRequestException('failed'))->getStatusCode(),
            'a transport error has no http status',
        );
    }
}
