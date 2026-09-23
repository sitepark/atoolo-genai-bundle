<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Dto\Indexer;

use Atoolo\GenAi\Dto\Indexer\Link;
use Atoolo\GenAi\Dto\Indexer\LinkSection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinkSection::class)]
class LinkSectionTest extends TestCase
{
    public function testHeadlineAndLinks(): void
    {
        $section = new LinkSection('Service', [new Link('/form', 'Antrag')]);

        $this->assertEquals('links', $section->getType(), 'unexpected type');
        $this->assertEquals(
            [
                'type' => 'links',
                'headline' => 'Service',
                'links' => [['url' => '/form', 'label' => 'Antrag']],
            ],
            $section->jsonSerialize(),
            'unexpected section',
        );
    }
}
