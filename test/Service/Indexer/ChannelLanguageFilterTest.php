<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer;

use Atoolo\GenAi\Service\Indexer\ChannelLanguageFilter;
use Atoolo\Index\Service\Indexer\ResourceFilter;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceTenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelLanguageFilter::class)]
class ChannelLanguageFilterTest extends TestCase
{
    public function testAcceptsChannelLanguage(): void
    {
        $filter = $this->createFilter(true);

        $this->assertTrue(
            $filter->accept($this->createResource('de_DE')),
            'a resource in the channel language should be accepted',
        );
    }

    public function testRejectsTranslation(): void
    {
        $filter = $this->createFilter(true);

        $this->assertFalse(
            $filter->accept($this->createResource('en_US')),
            'a translation should be rejected',
        );
    }

    public function testRejectsWhatTheInnerFilterRejects(): void
    {
        $filter = $this->createFilter(false);

        $this->assertFalse(
            $filter->accept($this->createResource('de_DE')),
            'the inner filter should still be asked',
        );
    }

    private function createFilter(bool $innerAccepts): ChannelLanguageFilter
    {
        $inner = $this->createStub(ResourceFilter::class);
        $inner->method('accept')->willReturn($innerAccepts);

        return new ChannelLanguageFilter($inner, $this->createResourceChannel());
    }

    private function createResource(string $locale): Resource
    {
        return new Resource(
            '/a/b.php',
            '123',
            'b',
            'content',
            ResourceLanguage::of($locale),
            new DataBag(['locale' => $locale]),
        );
    }

    private function createResourceChannel(): ResourceChannel
    {
        return new ResourceChannel(
            '',
            'WWW',
            '',
            '',
            false,
            '',
            'de_DE',
            '',
            '',
            '',
            'www',
            ['en_US'],
            new DataBag([]),
            $this->createStub(ResourceTenant::class),
        );
    }
}
