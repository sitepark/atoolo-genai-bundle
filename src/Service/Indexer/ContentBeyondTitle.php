<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer;

use Atoolo\GenAi\Dto\Indexer\TextSection;

/**
 * Decides whether a document has content worth indexing beyond its title.
 *
 * Many articles consist of little more than their title: a job offer whose
 * text is only "51 Jugendamt", a German course whose text is "A2" or a part
 * of its title, a test article with "text" or "fsdfdfdf". Such a document
 * answers no question, it only crowds out the documents that do. A short but
 * concrete text, on the other hand, is kept, e.g. "Entsorgungsweg: Rückgabe
 * an Verkaufsstelle" of the waste ABC.
 *
 * A document has content when the words of its text that are not words of
 * its title, headline or kicker have at least {@see MIN_CHARACTERS} letters
 * or digits together; every occurrence counts, punctuation and whitespace do
 * not. The text of an article is its intro, the headline of every section
 * and the HTML of every text section - with the alt text of its images, as
 * the application turns an image into its alt text. Link sections do not
 * count. The text of a medium is its raw text.
 *
 * The rule must match `ContentBeyondTitle` of the GenAI application, which
 * skips such a document as a safety net. It must never be stricter than
 * that one: when in doubt the document is sent, and what passes here is
 * caught there.
 */
final class ContentBeyondTitle
{
    public const MIN_CHARACTERS = 20;

    public function suffices(GenAiDocument $doc): bool
    {
        $titleWords = array_fill_keys(
            $this->words(implode(' ', [
                $doc->title ?? '',
                $doc->headline ?? '',
                $doc->kicker ?? '',
            ])),
            true,
        );

        $characters = 0;
        foreach ($this->texts($doc) as $text) {
            foreach ($this->words($text) as $word) {
                if (isset($titleWords[$word])) {
                    continue;
                }
                $characters += mb_strlen($word);
                if ($characters >= self::MIN_CHARACTERS) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return iterable<string>
     */
    private function texts(GenAiDocument $doc): iterable
    {
        if ($doc->isMedia()) {
            yield $doc->rawText ?? '';
            return;
        }
        yield $this->htmlToText($doc->intro ?? '');
        foreach ($doc->content as $section) {
            yield $section->headline;
            if ($section instanceof TextSection) {
                yield $this->htmlToText($section->html);
            }
        }
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace(
            '/<img\b[^>]*?\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>/iu',
            ' $1$2 ',
            $html,
        ) ?? $html;
        // a space, so that the words of adjacent blocks do not merge
        $text = preg_replace('/<[^>]*>/u', ' ', $html) ?? $html;
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return string[]
     */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);
        return $matches[0];
    }
}
