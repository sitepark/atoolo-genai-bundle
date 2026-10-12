<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Dto\Assistant\Filter;

use Atoolo\GenAi\Dto\Assistant\Filter\AndFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\CategoryFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\NotFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\ObjectTypeFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\OrFilter;
use Atoolo\GenAi\Dto\Assistant\Filter\QuestionFilter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionFilter::class)]
#[CoversClass(AndFilter::class)]
#[CoversClass(CategoryFilter::class)]
#[CoversClass(NotFilter::class)]
#[CoversClass(ObjectTypeFilter::class)]
#[CoversClass(OrFilter::class)]
class QuestionFilterTest extends TestCase
{
    public function testToVariables(): void
    {
        $this->assertEquals(
            [
                ['and' => [
                    ['objectTypes' => ['news', 'media']],
                    ['not' => ['categoryIds' => ['3']]],
                ]],
                ['or' => [['categoryIds' => ['4']]]],
            ],
            QuestionFilter::toVariables([
                new AndFilter([
                    new ObjectTypeFilter([2 => 'news', 5 => 'media']),
                    new NotFilter(new CategoryFilter(['3'])),
                ]),
                new OrFilter([new CategoryFilter(['4'])]),
            ]),
            'the filter should be the variable of the application',
        );
    }

    public function testFromArrays(): void
    {
        $this->assertEquals(
            [
                new NotFilter(new ObjectTypeFilter(['news', 'notice'])),
                new OrFilter([
                    new CategoryFilter(['3']),
                    new AndFilter([new ObjectTypeFilter(['media'])]),
                ]),
            ],
            QuestionFilter::fromArrays([
                ['not' => ['objectTypes' => ['news', 'notice']]],
                ['or' => [
                    ['categoryIds' => ['3']],
                    ['and' => [['objectTypes' => ['media']]]],
                ]],
            ]),
            'the configuration should become the filter',
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidFilters(): array
    {
        return [
            'no list' => [['objectTypes' => ['news']]],
            'no field' => [[[]]],
            'two fields' => [[['objectTypes' => ['news'], 'categoryIds' => ['3']]]],
            'unknown field' => [[['types' => ['news']]]],
            'empty values' => [[['objectTypes' => []]]],
            'blank value' => [[['objectTypes' => [' ']]]],
            'no string' => [[['categoryIds' => [3]]]],
            'empty or' => [[['or' => []]]],
            'not without condition' => [[['not' => 'news']]],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function testFromArraysWithInvalidFilter(mixed $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var array<mixed> $data */
        QuestionFilter::fromArrays($data);
    }
}
