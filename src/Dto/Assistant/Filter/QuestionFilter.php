<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Dto\Assistant\Filter;

use InvalidArgumentException;

/**
 * A condition the documents that answer a question must meet, mirroring
 * the `QuestionFilter` of the GenAI application: the documents of certain
 * object types or categories, or other conditions combined with AND, OR
 * and NOT. The documents of a type are left out with
 * `new NotFilter(new ObjectTypeFilter([...]))`.
 */
abstract class QuestionFilter
{
    /**
     * The condition as the GraphQL variable of the application expects it.
     *
     * @return array<string, mixed>
     */
    abstract public function toVariable(): array;

    /**
     * @param QuestionFilter[] $filters
     * @return list<array<string, mixed>>
     */
    public static function toVariables(array $filters): array
    {
        return array_values(array_map(
            static fn(QuestionFilter $filter) => $filter->toVariable(),
            $filters,
        ));
    }

    /**
     * The conditions of a configuration, in the form of the GraphQL
     * variable: a list of maps that each set exactly one of `objectTypes`,
     * `categoryIds`, `and`, `or` and `not`.
     *
     * @param array<mixed> $data
     * @return QuestionFilter[]
     * @throws InvalidArgumentException if a condition is not of that form
     */
    public static function fromArrays(array $data): array
    {
        if (!array_is_list($data)) {
            throw new InvalidArgumentException(
                'A list of filters is expected, got ' . json_encode($data),
            );
        }
        return array_map(
            static fn(mixed $filter) => self::fromArray($filter),
            $data,
        );
    }

    /**
     * @throws InvalidArgumentException if the condition is not a map that
     *   sets exactly one of `objectTypes`, `categoryIds`, `and`, `or` and
     *   `not`
     */
    public static function fromArray(mixed $data): QuestionFilter
    {
        if (!is_array($data) || count($data) !== 1) {
            throw new InvalidArgumentException(
                'A filter must set exactly one of objectTypes, categoryIds, '
                . 'and, or, not: ' . json_encode($data),
            );
        }
        $value = reset($data);
        return match (key($data)) {
            'objectTypes' => new ObjectTypeFilter(self::strings($value)),
            'categoryIds' => new CategoryFilter(self::strings($value)),
            'and' => new AndFilter(self::fromArrays(self::list($value))),
            'or' => new OrFilter(self::fromArrays(self::list($value))),
            'not' => new NotFilter(self::fromArray($value)),
            default => throw new InvalidArgumentException(
                'Unknown filter field "' . key($data) . '"',
            ),
        };
    }

    /**
     * @return string[]
     */
    private static function strings(mixed $value): array
    {
        $strings = [];
        foreach (self::list($value) as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(
                    'A filter expects a list of non-empty strings, got '
                    . json_encode($value),
                );
            }
            $strings[] = $item;
        }
        return $strings;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new InvalidArgumentException(
                'A filter expects a non-empty list, got ' . json_encode($value),
            );
        }
        return $value;
    }
}
