<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\SiteKit;

use Atoolo\GenAi\Dto\Indexer\Category;
use Atoolo\GenAi\Dto\Indexer\ContentSection;
use Atoolo\GenAi\Dto\Indexer\TextSection;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\Index\Service\Indexer\DocumentEnricher;
use Atoolo\Index\Service\Indexer\IndexDocument;
use Atoolo\Resource\Resource;
use IntlDateFormatter;

/**
 * Adds the facts of an event of the events calendar to the GenAI document:
 * when it takes place and where, who organizes it and where to get tickets.
 *
 * The description of an event is ordinary content and already mapped by the
 * {@see DefaultGenAiDocumentEnricher}. The facts are not: the dates are in
 * `metadata.scheduling`, and venue, organizer and ticket agency are contact
 * sections of the content that hold contact points of their own - an event
 * has no `metadata.contactPoint`. Without them the application could not say
 * when or where an event takes place.
 *
 * The dates become one list, so the application never tears them apart. The
 * CMS expands a series into one entry per occurrence, which may be hundreds;
 * only the first ones are listed, as the website does, followed by the last
 * date. The date of the document stays unset: an event is not the more
 * relevant the more recently it was published.
 *
 * @phpstan-import-type ContactPoint from ContactPointSections
 * @phpstan-type ScheduleDate array{
 *     scheduleId?:int|string,
 *     contentType?:string,
 *     from?:int,
 *     to?:int,
 *     fullDay?:bool,
 *     hasBeginTime?:bool,
 *     hasEndTime?:bool,
 *     status?:string
 * }
 * @implements DocumentEnricher<GenAiDocument>
 */
class EventGenAiDocumentEnricher implements DocumentEnricher
{
    private const OBJECT_TYPE = 'eventsCalendar-event';

    private const MAX_DATES = 15;

    private const CONTACT_SECTION_HEADLINES = [
        'eventsCalendar-venue' => 'Veranstaltungsort',
        'eventsCalendar-ticketAgency' => 'Vorverkauf',
        'eventsCalendar-organizer' => 'Veranstalter',
        'eventsCalendar-organizerAdditional' => 'Weiterer Veranstalter',
        'eventsCalendar-organizerCooperation' => 'In Kooperation mit',
    ];

    private const STATUS = [
        'cancelled' => 'abgesagt',
        'soldOut' => 'ausverkauft',
        'postPoned' => 'verlegt',
        'expired' => 'vergangen',
    ];

    private readonly IntlDateFormatter $dateFormatter;

    private readonly IntlDateFormatter $timeFormatter;

    private readonly ContactPointSections $contactPointSections;

    public function __construct(string $timeZone = 'Europe/Berlin')
    {
        $this->dateFormatter = new IntlDateFormatter(
            'de_DE',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timeZone,
            null,
            'ccc. dd.MM.yyyy',
        );
        $this->timeFormatter = new IntlDateFormatter(
            'de_DE',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timeZone,
            null,
            'HH:mm',
        );
        $this->contactPointSections = new ContactPointSections();
    }

    public function cleanup(): void {}

    public function enrichDocument(
        Resource $resource,
        IndexDocument $doc,
        string $processId,
    ): IndexDocument {
        if ($resource->objectType !== self::OBJECT_TYPE || $doc->isMedia()) {
            return $doc;
        }

        /** @var array<ScheduleDate> $scheduling */
        $scheduling = $resource->data->getArray('metadata.scheduling');
        $dates = $this->toDatesSection($scheduling);
        if ($dates !== null) {
            $doc->content[] = $dates;
        }

        $this->collectContactSections(
            $resource->data->getArray('content'),
            $doc,
        );

        return $doc;
    }

    /**
     * @param array<ScheduleDate> $scheduling
     */
    private function toDatesSection(array $scheduling): ?TextSection
    {
        $dates = $this->dates($scheduling);
        if (empty($dates)) {
            return null;
        }

        $html = '<ul>';
        foreach (array_slice($dates, 0, self::MAX_DATES) as $date) {
            $html .= '<li>' . $this->escape($date['text']) . '</li>';
        }
        if (count($dates) > self::MAX_DATES) {
            $last = $dates[array_key_last($dates)];
            $html .= '<li>' . $this->escape(
                'Weitere Termine bis ' . $last['lastDay'],
            ) . '</li>';
        }
        $html .= '</ul>';

        return new TextSection('Termine', $html);
    }

    /**
     * The CMS keeps a date over several days as one entry per day, marked
     * as its start, a day in between and its end. They are joined into one
     * date again; the start may already be gone, as only the future days
     * are kept.
     *
     * @param array<ScheduleDate> $scheduling
     * @return list<array{text:string, lastDay:string}>
     */
    private function dates(array $scheduling): array
    {
        $dates = [];
        $start = null;
        $previous = null;
        foreach ($scheduling as $date) {
            if (!is_int($date['from'] ?? null)) {
                continue;
            }
            $contentType = ' ' . ($date['contentType'] ?? '') . ' ';
            if (!str_contains($contentType, ' schedule_multi ')) {
                if ($start !== null && $previous !== null) {
                    $dates[] = $this->multiDay($start, $previous);
                    $start = null;
                }
                $dates[] = $this->singleDay($date);
                continue;
            }

            if (
                $start === null
                || str_contains($contentType, ' schedule_start ')
            ) {
                if ($start !== null && $previous !== null) {
                    $dates[] = $this->multiDay($start, $previous);
                }
                $start = $date;
            }
            $previous = $date;
            if (str_contains($contentType, ' schedule_end ')) {
                $dates[] = $this->multiDay($start, $date);
                $start = null;
            }
        }
        if ($start !== null && $previous !== null) {
            $dates[] = $this->multiDay($start, $previous);
        }

        return array_values(
            array_filter($dates, fn($date) => $date['text'] !== ''),
        );
    }

    /**
     * @param ScheduleDate $date
     * @return array{text:string, lastDay:string}
     */
    private function singleDay(array $date): array
    {
        $day = $this->formatDate($date['from'] ?? null);
        $text = $day;
        $begin = $this->beginTime($date);
        if ($text !== '' && $begin !== '') {
            $end = $this->endTime($date);
            $text .= ', ' . $begin
                . ($end !== '' ? ' – ' . $end : '') . ' Uhr';
        }

        return [
            'text' => $this->withStatus($text, $date),
            'lastDay' => $day,
        ];
    }

    /**
     * @param ScheduleDate $start
     * @param ScheduleDate $end
     * @return array{text:string, lastDay:string}
     */
    private function multiDay(array $start, array $end): array
    {
        $firstDay = $this->formatDate($start['from'] ?? null);
        $lastDay = $this->formatDate($end['to'] ?? $end['from'] ?? null);
        if ($firstDay === '') {
            return ['text' => '', 'lastDay' => ''];
        }

        $text = $firstDay;
        $begin = $this->beginTime($start);
        if ($begin !== '') {
            $text .= ', ' . $begin . ' Uhr';
        }
        if ($lastDay !== '' && $lastDay !== $firstDay) {
            $text .= ' – ' . $lastDay;
            $endTime = $this->endTime($end);
            if ($endTime !== '') {
                $text .= ', ' . $endTime . ' Uhr';
            }
        }

        return [
            'text' => $this->withStatus($text, $start),
            'lastDay' => $lastDay !== '' ? $lastDay : $firstDay,
        ];
    }

    /**
     * @param ScheduleDate $date
     */
    private function beginTime(array $date): string
    {
        if (
            ($date['fullDay'] ?? false)
            || !($date['hasBeginTime'] ?? false)
        ) {
            return '';
        }
        return $this->formatTime($date['from'] ?? null);
    }

    /**
     * Without an end time the CMS ends the date at 23:59, which is no time
     * anybody would name.
     *
     * @param ScheduleDate $date
     */
    private function endTime(array $date): string
    {
        if (
            ($date['fullDay'] ?? false)
            || !($date['hasEndTime'] ?? false)
        ) {
            return '';
        }
        $time = $this->formatTime($date['to'] ?? null);
        return $time === '23:59' ? '' : $time;
    }

    /**
     * @param ScheduleDate $date
     */
    private function withStatus(string $text, array $date): string
    {
        $status = self::STATUS[$date['status'] ?? ''] ?? null;
        return $text === '' || $status === null
            ? $text
            : $text . ' (' . $status . ')';
    }

    /**
     * Venue, ticket agency and organizers are sections of the content that
     * hold contact points or a free text. Their categories are those of the
     * venue or organizer, so a question can be limited to them, as the Solr
     * index allows.
     *
     * @param array<mixed,mixed> $node
     */
    private function collectContactSections(
        array $node,
        GenAiDocument $doc,
    ): void {
        if (($node['type'] ?? null) === 'eventsCalendar.contactSection') {
            $id = is_string($node['id'] ?? null) ? $node['id'] : '';
            $headline = self::CONTACT_SECTION_HEADLINES[$id] ?? 'Kontakt';
            foreach ($this->contactSections($node, $headline) as $section) {
                $doc->content[] = $section;
            }
            $model = $node['model'] ?? null;
            if (is_array($model)) {
                $this->addCategories($model, $doc);
            }
            return;
        }

        $items = $node['items'] ?? null;
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if (is_array($item)) {
                $this->collectContactSections($item, $doc);
            }
        }
    }

    /**
     * @param array<mixed,mixed> $node
     * @return ContentSection[]
     */
    private function contactSections(array $node, string $headline): array
    {
        $sections = [];
        $items = $node['items'] ?? null;
        if (!is_array($items)) {
            return $sections;
        }
        foreach ($items as $item) {
            if (!is_array($item) || !is_array($item['model'] ?? null)) {
                continue;
            }
            $model = $item['model'];
            if (($item['type'] ?? null) === 'contactPoint') {
                /** @var ContactPoint $model */
                $contact = $this->contactPointSections->contact(
                    $model,
                    $headline,
                );
                if ($contact !== null) {
                    $sections[] = $contact;
                }
                $openingHours = $this->contactPointSections->openingHours(
                    $model['openingHours'] ?? [],
                    'Öffnungszeiten (' . $headline . ')',
                );
                if ($openingHours !== null) {
                    $sections[] = $openingHours;
                }
            } elseif (($item['type'] ?? null) === 'contactFreeText') {
                $freeText = $this->toFreeTextSection($model, $headline);
                if ($freeText !== null) {
                    $sections[] = $freeText;
                }
            }
        }
        return $sections;
    }

    /**
     * @param array<mixed,mixed> $model
     */
    private function toFreeTextSection(
        array $model,
        string $headline,
    ): ?TextSection {
        $html = '';
        foreach (['headline', 'description'] as $name) {
            $text = is_string($model[$name] ?? null)
                ? trim($model[$name])
                : '';
            if ($text !== '') {
                $html .= '<p>' . $this->escape($text) . '</p>';
            }
        }
        return $html === '' ? null : new TextSection($headline, $html);
    }

    /**
     * @param array<mixed,mixed> $model
     */
    private function addCategories(array $model, GenAiDocument $doc): void
    {
        $known = [];
        foreach ($doc->categories as $category) {
            $known[$category->id] = true;
        }
        foreach (['categories', 'categoriesPath'] as $name) {
            $list = $model[$name] ?? null;
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $category) {
                if (!is_array($category)) {
                    continue;
                }
                $id = $category['id'] ?? null;
                $id = is_int($id) || is_string($id) ? (string) $id : '';
                if ($id === '' || isset($known[$id])) {
                    continue;
                }
                $known[$id] = true;
                $doc->categories[] = new Category(
                    $id,
                    is_string($category['name'] ?? null)
                        ? $category['name']
                        : '',
                );
            }
        }
    }

    private function formatDate(?int $timestamp): string
    {
        if ($timestamp === null) {
            return '';
        }
        $formatted = $this->dateFormatter->format($timestamp);
        return $formatted === false ? '' : $formatted;
    }

    private function formatTime(?int $timestamp): string
    {
        if ($timestamp === null) {
            return '';
        }
        $formatted = $this->timeFormatter->format($timestamp);
        return $formatted === false ? '' : $formatted;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
