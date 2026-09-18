<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenAiDocument::class)]
class GenAiDocumentTest extends TestCase
{
    public function testNullFieldsAreLeftOut(): void
    {
        $doc = new GenAiDocument();
        $doc->id = '123';

        $fields = $doc->getFields();

        $this->assertArrayNotHasKey(
            'title',
            $fields,
            'a field that was never set should not be sent',
        );
        $this->assertEquals('123', $fields['id'], 'unexpected id');
    }

    public function testDatesAreFormattedAsAtom(): void
    {
        $doc = new GenAiDocument();
        $date = new DateTime('2024-01-31T11:15:10+00:00');
        $doc->changed = $date;
        $doc->date_list = [$date];

        $fields = $doc->getFields();

        $this->assertEquals(
            '2024-01-31T11:15:10+00:00',
            $fields['changed'],
            'unexpected date format',
        );
        $this->assertEquals(
            ['2024-01-31T11:15:10+00:00'],
            $fields['date_list'],
            'dates inside a list should be formatted as well',
        );
    }

    public function testMetaIsOnlySentWhenFilled(): void
    {
        $doc = new GenAiDocument();

        $this->assertArrayNotHasKey(
            'meta',
            $doc->getFields(),
            'an empty meta should not be sent',
        );

        $doc->setMeta('department', 'culture');

        $this->assertEquals(
            ['department' => 'culture'],
            $doc->getFields()['meta'],
            'unexpected meta',
        );
    }

    public function testContentHashIsStable(): void
    {
        $this->assertEquals(
            $this->createFilledDocument()->getContentHash(),
            $this->createFilledDocument()->getContentHash(),
            'the same content should produce the same hash',
        );
    }

    public function testContentHashChangesWithContent(): void
    {
        $other = $this->createFilledDocument();
        $other->content = 'something else';

        $this->assertNotEquals(
            $this->createFilledDocument()->getContentHash(),
            $other->getContentHash(),
            'changed content should produce a different hash',
        );
    }

    public function testContentHashIsNotOverwritten(): void
    {
        $doc = $this->createFilledDocument();
        $doc->content_hash = 'sha256:given';

        $this->assertEquals(
            'sha256:given',
            $doc->getFields()['content_hash'],
            'a hash that was set explicitly should be kept',
        );
    }

    public function testContentHashIsPartOfTheFields(): void
    {
        $fields = $this->createFilledDocument()->getFields();

        $this->assertStringStartsWith(
            'sha256:',
            $fields['content_hash'],
            'the hash should be sent along',
        );
    }

    private function createFilledDocument(): GenAiDocument
    {
        $doc = new GenAiDocument();
        $doc->title = 'title';
        $doc->description = 'description';
        $doc->content = 'content';
        return $doc;
    }
}
