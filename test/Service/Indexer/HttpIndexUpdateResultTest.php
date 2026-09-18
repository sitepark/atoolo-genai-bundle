<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\Indexer\HttpIndexUpdateResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpIndexUpdateResult::class)]
class HttpIndexUpdateResultTest extends TestCase
{
    public function testIsSuccess(): void
    {
        $result = new HttpIndexUpdateResult(5);

        $this->assertTrue($result->isSuccess(), 'should be a success');
        $this->assertNull(
            $result->getErrorMessage(),
            'a success has no error message',
        );
        $this->assertEquals(5, $result->getAccepted(), 'unexpected accepted');
    }

    public function testRejectedWithoutErrors(): void
    {
        $result = new HttpIndexUpdateResult(1, 2);

        $this->assertFalse($result->isSuccess(), 'should not be a success');
        $this->assertEquals(
            '2 documents were rejected',
            $result->getErrorMessage(),
            'unexpected error message',
        );
        $this->assertEquals(2, $result->getRejected(), 'unexpected rejected');
    }

    public function testRejectedWithErrors(): void
    {
        $result = new HttpIndexUpdateResult(1, 1, ['123' => 'too long']);

        $this->assertEquals(
            '1 documents were rejected - 123: too long',
            $result->getErrorMessage(),
            'the error message should name the document',
        );
    }
}
