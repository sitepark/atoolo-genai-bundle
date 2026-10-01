<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\GraphQL;

use Atoolo\GenAi\Dto\Assistant\Answer;
use Atoolo\GenAi\Dto\Assistant\AnswerCutOffError;
use Atoolo\GenAi\Dto\Assistant\AnswerLinksSection;
use Atoolo\GenAi\Dto\Assistant\AnswerTextSection;
use Atoolo\GenAi\Dto\Assistant\NoDocumentsError;
use Atoolo\GenAi\Dto\Assistant\NoMatchingDocumentsError;
use Atoolo\GenAi\Dto\Assistant\UnansweredError;
use Atoolo\GenAi\GraphQL\TypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

#[CoversClass(TypeResolver::class)]
class TypeResolverTest extends TestCase
{
    /**
     * @return iterable<string,array{object,string}>
     */
    public static function values(): iterable
    {
        yield 'answer' => [new Answer(), 'GenAiAnswer'];
        yield 'no documents' => [
            new NoDocumentsError(),
            'GenAiNoDocumentsError',
        ];
        yield 'no matching documents' => [
            new NoMatchingDocumentsError(),
            'GenAiNoMatchingDocumentsError',
        ];
        yield 'cut off' => [
            new AnswerCutOffError(),
            'GenAiAnswerCutOffError',
        ];
        yield 'unknown error' => [
            new UnansweredError(),
            'GenAiUnansweredError',
        ];
        yield 'text section' => [
            new AnswerTextSection(),
            'GenAiTextSection',
        ];
        yield 'links section' => [
            new AnswerLinksSection(),
            'GenAiLinksSection',
        ];
    }

    #[DataProvider('values')]
    public function testResolveType(object $value, string $expected): void
    {
        $this->assertEquals(
            $expected,
            (new TypeResolver())->resolveType($value),
            'unexpected GraphQL type',
        );
    }

    public function testResolveTypeOfAnUnknownValue(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new TypeResolver())->resolveType(new stdClass());
    }
}
