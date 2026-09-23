<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Dto\Indexer;

use Atoolo\GenAi\Dto\Indexer\Link;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Link::class)]
class LinkTest extends TestCase
{
    public function testUrlAndLabel(): void
    {
        $this->assertEquals(
            ['url' => '/form', 'label' => 'Antrag'],
            (new Link('/form', 'Antrag'))->jsonSerialize(),
            'unexpected link',
        );
    }

    public function testEmptyLabelIsLeftOut(): void
    {
        $this->assertEquals(
            ['url' => '/form'],
            (new Link('/form'))->jsonSerialize(),
            'an empty label should not be sent',
        );
    }
}
