<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\SiteKit;

/**
 * The content of a SiteKit resource is a tree whose root holds areas. Most
 * of them belong to the article - besides `main`, a project may place
 * further areas the page embeds, such as the description of an
 * organisation. Some hold another version of the same article, such as
 * `stuttgart.easyLanguage` the text in easy language; they are left out,
 * so an answer is built from the article and not from a second wording of
 * it.
 */
final class ContentAreas
{
    /**
     * The types of the areas that are not indexed.
     */
    public const EXCLUDED = ['stuttgart.easyLanguage'];

    /**
     * Returns the content without its excluded areas.
     *
     * @param array<mixed,mixed> $content
     * @return array<mixed,mixed>
     */
    public static function of(array $content): array
    {
        $items = $content['items'] ?? null;
        if (!is_array($items)) {
            return $content;
        }

        $content['items'] = array_values(array_filter(
            $items,
            static fn(mixed $item): bool => !is_array($item)
                || !in_array($item['type'] ?? null, self::EXCLUDED, true),
        ));

        return $content;
    }
}
