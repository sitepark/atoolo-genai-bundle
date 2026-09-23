<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Dto\Indexer;

use Atoolo\GenAi\Dto\Indexer\TextSection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextSection::class)]
class TextSectionTest extends TestCase
{
    public function testHeadlineAndHtml(): void
    {
        $section = new TextSection('Unterlagen', '<p>Ein Foto.</p>');

        $this->assertEquals('text', $section->getType(), 'unexpected type');
        $this->assertEquals(
            [
                'type' => 'text',
                'headline' => 'Unterlagen',
                'html' => '<p>Ein Foto.</p>',
            ],
            $section->jsonSerialize(),
            'unexpected section',
        );
    }

    public function testEmptyHeadlineIsLeftOut(): void
    {
        $this->assertEquals(
            ['type' => 'text', 'html' => '<p>Ein Foto.</p>'],
            (new TextSection('', '<p>Ein Foto.</p>'))->jsonSerialize(),
            'an empty headline should not be sent',
        );
    }
}
