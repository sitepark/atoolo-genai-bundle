<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\SiteKit;

/**
 * The content of a SiteKit resource is a tree whose root holds areas: the
 * `main` area is the article, others hold further versions of it, such as
 * `stuttgart.easyLanguage` the text in easy language. Only the `main` area
 * is indexed, so an answer is built from the article and not from a second
 * wording of it.
 */
final class MainContent
{
    /**
     * Returns the nodes of the content to walk: the `main` areas, or the
     * whole content if it has none.
     *
     * @param array<mixed,mixed> $content
     * @return array<array<mixed,mixed>>
     */
    public static function of(array $content): array
    {
        $items = $content['items'] ?? null;
        if (!is_array($items)) {
            return [$content];
        }

        $main = array_values(array_filter(
            $items,
            static fn(mixed $item): bool => is_array($item)
                && ($item['type'] ?? null) === 'main',
        ));

        return $main === [] ? [$content] : $main;
    }
}
