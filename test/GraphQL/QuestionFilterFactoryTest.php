<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\GraphQL;

use Atoolo\GenAi\Dto\Assistant\Filter\AndFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\CategoryFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\NotFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\ObjectTypeFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\OrFilter;
use Atoolo\GenAi\Exception\AssistantErrorType;
use Atoolo\GenAi\GraphQL\AssistantError;
use Atoolo\GenAi\GraphQL\Input\QuestionFilterInput;
use Atoolo\GenAi\GraphQL\QuestionFilterFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionFilterFactory::class)]
class QuestionFilterFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $news = new QuestionFilterInput();
        $news->objectTypes = ['news'];
        $category = new QuestionFilterInput();
        $category->categoryIds = ['3'];
        $not = new QuestionFilterInput();
        $not->not = $news;
        $or = new QuestionFilterInput();
        $or->or = [$category, $not];
        $and = new QuestionFilterInput();
        $and->and = [$or];

        $this->assertEquals(
            [
                new AndFilter([
                    new OrFilter([
                        new CategoryFilter(['3']),
                        new NotFilter(new ObjectTypeFilter(['news'])),
                    ]),
                ]),
            ],
            (new QuestionFilterFactory())->create([$and]),
            'every input should become the filter of its field',
        );
    }

    public function testInputWithoutField(): void
    {
        $this->expectBadRequest();
        (new QuestionFilterFactory())->create([new QuestionFilterInput()]);
    }

    public function testInputWithSeveralFields(): void
    {
        $input = new QuestionFilterInput();
        $input->objectTypes = ['news'];
        $input->categoryIds = ['3'];

        $this->expectBadRequest();
        (new QuestionFilterFactory())->create([$input]);
    }

    public function testNestedInputWithoutField(): void
    {
        $input = new QuestionFilterInput();
        $input->not = new QuestionFilterInput();

        $this->expectBadRequest();
        (new QuestionFilterFactory())->create([$input]);
    }

    private function expectBadRequest(): void
    {
        $this->expectException(AssistantError::class);
        $this->expectExceptionMessage('exactly one');
    }
}
