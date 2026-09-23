<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Dto\Indexer;

use Atoolo\GenAi\Dto\Indexer\Category;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Category::class)]
class CategoryTest extends TestCase
{
    public function testRootCategoryHasNoParent(): void
    {
        $this->assertEquals(
            ['id' => '3', 'name' => 'Bürgerservice'],
            (new Category('3', 'Bürgerservice'))->jsonSerialize(),
            'a root category should not send a parent',
        );
    }

    public function testParentIsNested(): void
    {
        $category = new Category(
            '12',
            'Dokumente',
            new Category('3', 'Bürgerservice'),
        );

        $this->assertEquals(
            [
                'id' => '12',
                'name' => 'Dokumente',
                'parent' => ['id' => '3', 'name' => 'Bürgerservice'],
            ],
            $category->jsonSerialize(),
            'unexpected category tree',
        );
    }
}
