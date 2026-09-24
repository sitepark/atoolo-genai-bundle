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
        $result = new HttpIndexUpdateResult(5, 0, 12);

        $this->assertTrue($result->isSuccess(), 'should be a success');
        $this->assertNull(
            $result->getErrorMessage(),
            'a success has no error message',
        );
        $this->assertEquals(5, $result->getAccepted(), 'unexpected accepted');
        $this->assertEquals(12, $result->getChunks(), 'unexpected chunks');
    }

    public function testRejected(): void
    {
        $result = new HttpIndexUpdateResult(1, 2);

        $this->assertFalse($result->isSuccess(), 'should not be a success');
        $this->assertEquals(
            '2 of 3 documents were not indexed',
            $result->getErrorMessage(),
            'unexpected error message',
        );
        $this->assertEquals(2, $result->getRejected(), 'unexpected rejected');
    }

    public function testUnchangedDocumentsAreNoError(): void
    {
        $result = new HttpIndexUpdateResult(1, 2, 3, 4);

        $this->assertEquals(4, $result->getUnchanged(), 'unexpected unchanged');
        $this->assertEquals(
            '2 of 7 documents were not indexed',
            $result->getErrorMessage(),
            'the unchanged documents belong to the sent ones',
        );
    }

    public function testEmptyResultIsSuccess(): void
    {
        $result = new HttpIndexUpdateResult();

        $this->assertTrue(
            $result->isSuccess(),
            'a bulk that sent nothing is a success',
        );
    }
}
