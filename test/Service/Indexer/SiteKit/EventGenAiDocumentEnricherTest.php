<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer\SiteKit;

use Atoolo\GenAi\Dto\Indexer\Category;
use Atoolo\GenAi\Dto\Indexer\TextSection;
use Atoolo\GenAi\Service\Indexer\GenAiDocument;
use Atoolo\GenAi\Service\Indexer\SiteKit\ContactPointSections;
use Atoolo\GenAi\Service\Indexer\SiteKit\EventGenAiDocumentEnricher;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLanguage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventGenAiDocumentEnricher::class)]
#[UsesClass(ContactPointSections::class)]
class EventGenAiDocumentEnricherTest extends TestCase
{
    private EventGenAiDocumentEnricher $enricher;

    public function setUp(): void
    {
        $this->enricher = new EventGenAiDocumentEnricher();
    }

    public function testCleanup(): void
    {
        $this->enricher->cleanup();
        $this->addToAssertionCount(1);
    }

    public function testOtherObjectTypesAreLeftAlone(): void
    {
        $doc = $this->enrich(
            ['metadata' => ['scheduling' => [$this->date('2026-10-03 19:00')]]],
            'news',
        );

        $this->assertEmpty($doc->content, 'a news should not get dates');
    }

    public function testAMediumIsLeftAlone(): void
    {
        $doc = new GenAiDocument();
        $doc->type = GenAiDocument::TYPE_MEDIA;
        $this->enricher->enrichDocument(
            $this->createResource([
                'metadata' => [
                    'scheduling' => [$this->date('2026-10-03 19:00')],
                ],
            ]),
            $doc,
            'process-id',
        );

        $this->assertEmpty($doc->content, 'a medium should not get dates');
    }

    public function testDateWithBeginAndEnd(): void
    {
        $doc = $this->enrich(['metadata' => ['scheduling' => [
            $this->date('2026-10-03 19:00', '2026-10-03 22:00'),
        ]]]);

        $this->assertSection(
            'Termine',
            '<ul><li>Sa. 03.10.2026, 19:00 – 22:00 Uhr</li></ul>',
            $doc,
        );
    }

    public function testWithoutAnEndOnlyTheBeginIsNamed(): void
    {
        $date = $this->date('2026-10-03 19:00', '2026-10-03 23:59');
        $date['hasEndTime'] = false;
        $doc = $this->enrich(['metadata' => ['scheduling' => [$date]]]);

        $this->assertSection(
            'Termine',
            '<ul><li>Sa. 03.10.2026, 19:00 Uhr</li></ul>',
            $doc,
        );
    }

    public function testAnEndAtMidnightIsNoTime(): void
    {
        $doc = $this->enrich(['metadata' => ['scheduling' => [
            $this->date('2026-10-03 19:00', '2026-10-03 23:59'),
        ]]]);

        $this->assertSection(
            'Termine',
            '<ul><li>Sa. 03.10.2026, 19:00 Uhr</li></ul>',
            $doc,
        );
    }

    public function testAFullDayHasNoTime(): void
    {
        $date = $this->date('2026-10-03 00:00', '2026-10-03 23:59');
        $date['fullDay'] = true;
        $doc = $this->enrich(['metadata' => ['scheduling' => [$date]]]);

        $this->assertSection(
            'Termine',
            '<ul><li>Sa. 03.10.2026</li></ul>',
            $doc,
        );
    }

    public function testTheStatusOfADateIsNamed(): void
    {
        $date = $this->date('2026-10-03 19:00', '2026-10-03 22:00');
        $date['status'] = 'cancelled';
        $doc = $this->enrich(['metadata' => ['scheduling' => [$date]]]);

        $this->assertSection(
            'Termine',
            '<ul><li>Sa. 03.10.2026, 19:00 – 22:00 Uhr (abgesagt)</li></ul>',
            $doc,
        );
    }

    public function testTheDaysOfAMultiDayDateAreJoined(): void
    {
        $doc = $this->enrich(['metadata' => ['scheduling' => [
            $this->multi('schedule_start', '2026-10-02 18:00', '2026-10-02 23:59'),
            $this->multi('schedule_day', '2026-10-03 00:00', '2026-10-03 23:59'),
            $this->multi('schedule_end', '2026-10-04 00:00', '2026-10-04 20:00'),
            $this->date('2026-10-10 19:00', '2026-10-10 22:00'),
        ]]]);

        $this->assertSection(
            'Termine',
            '<ul>'
            . '<li>Fr. 02.10.2026, 18:00 Uhr – So. 04.10.2026, 20:00 Uhr</li>'
            . '<li>Sa. 10.10.2026, 19:00 – 22:00 Uhr</li>'
            . '</ul>',
            $doc,
        );
    }

    public function testAMultiDayDateWhoseStartIsGone(): void
    {
        $doc = $this->enrich(['metadata' => ['scheduling' => [
            $this->multi('schedule_day', '2026-10-03 00:00', '2026-10-03 23:59'),
            $this->multi('schedule_end', '2026-10-04 00:00', '2026-10-04 20:00'),
        ]]]);

        $this->assertSection(
            'Termine',
            '<ul><li>Sa. 03.10.2026 – So. 04.10.2026, 20:00 Uhr</li></ul>',
            $doc,
        );
    }

    public function testAMultiDayDateWithoutItsEnd(): void
    {
        $doc = $this->enrich(['metadata' => ['scheduling' => [
            $this->multi('schedule_start', '2026-10-02 18:00', '2026-10-02 23:59'),
            $this->multi('schedule_day', '2026-10-03 00:00', '2026-10-03 23:59'),
        ]]]);

        $this->assertSection(
            'Termine',
            '<ul><li>Fr. 02.10.2026, 18:00 Uhr – Sa. 03.10.2026</li></ul>',
            $doc,
        );
    }

    public function testASingleDateEndsAPendingMultiDayDate(): void
    {
        $doc = $this->enrich(['metadata' => ['scheduling' => [
            $this->multi('schedule_start', '2026-10-02 18:00', '2026-10-02 23:59'),
            $this->date('2026-10-10 19:00', '2026-10-10 22:00'),
        ]]]);

        $this->assertSection(
            'Termine',
            '<ul>'
            . '<li>Fr. 02.10.2026, 18:00 Uhr</li>'
            . '<li>Sa. 10.10.2026, 19:00 – 22:00 Uhr</li>'
            . '</ul>',
            $doc,
        );
    }

    public function testOnlyTheFirstDatesAreListed(): void
    {
        $scheduling = [];
        for ($day = 1; $day <= 20; $day++) {
            $scheduling[] = $this->date(
                sprintf('2026-10-%02d 10:00', $day),
                sprintf('2026-10-%02d 12:00', $day),
            );
        }
        $doc = $this->enrich(['metadata' => ['scheduling' => $scheduling]]);

        $section = $doc->content[0] ?? null;
        $this->assertInstanceOf(TextSection::class, $section);
        $this->assertEquals(
            16,
            substr_count($section->html, '<li>'),
            'fifteen dates and the last one should be listed',
        );
        $this->assertStringEndsWith(
            '<li>Do. 15.10.2026, 10:00 – 12:00 Uhr</li>'
            . '<li>Weitere Termine bis Di. 20.10.2026</li></ul>',
            $section->html,
            'the list should end with the last date',
        );
    }

    public function testDatesWithoutBeginAreSkipped(): void
    {
        $doc = $this->enrich(['metadata' => ['scheduling' => [
            ['contentType' => 'schedule schedule_single'],
        ]]]);

        $this->assertEmpty($doc->content, 'there should be no dates');
    }

    public function testVenueOrganizerAndTicketAgency(): void
    {
        $doc = $this->enrich(['content' => ['items' => [[
            'type' => 'main',
            'items' => [
                $this->contactSection('eventsCalendar-venue', [[
                    'type' => 'contactPoint',
                    'model' => [
                        'organisation' => 'Liederhalle',
                        'addressData' => [
                            'street' => 'Berliner Platz',
                            'housenumber' => '1',
                            'postalCode' => '70174',
                            'city' => 'Stuttgart',
                        ],
                        'openingHours' => [
                            'additionalText' => ['text' => '<p>Ab 18 Uhr.</p>'],
                        ],
                    ],
                ]]),
                $this->contactSection('eventsCalendar-ticketAgency', [[
                    'type' => 'contactFreeText',
                    'model' => [
                        'headline' => 'Abendkasse',
                        'description' => 'Tickets <nur> an der Abendkasse.',
                    ],
                ]]),
                $this->contactSection('eventsCalendar-organizer', [[
                    'type' => 'contactPoint',
                    'model' => ['organisation' => 'Kulturamt'],
                ]]),
            ],
        ]]]]);

        $this->assertEquals(
            [
                new TextSection(
                    'Veranstaltungsort',
                    '<ul><li>Name: Liederhalle</li>'
                    . '<li>Adresse: Berliner Platz 1, 70174 Stuttgart</li></ul>',
                ),
                new TextSection(
                    'Öffnungszeiten (Veranstaltungsort)',
                    '<p>Ab 18 Uhr.</p>',
                ),
                new TextSection(
                    'Vorverkauf',
                    '<p>Abendkasse</p>'
                    . '<p>Tickets &lt;nur&gt; an der Abendkasse.</p>',
                ),
                new TextSection(
                    'Veranstalter',
                    '<ul><li>Name: Kulturamt</li></ul>',
                ),
            ],
            $doc->content,
            'unexpected contact sections',
        );
    }

    public function testEmptyContactsAreSkipped(): void
    {
        $doc = $this->enrich(['content' => ['items' => [
            $this->contactSection('eventsCalendar-venue', [
                ['type' => 'contactPoint', 'model' => []],
                ['type' => 'contactFreeText', 'model' => ['headline' => ' ']],
                ['type' => 'contactTeaserList', 'model' => []],
                ['type' => 'contactPoint'],
            ]),
            [
                'type' => 'eventsCalendar.contactSection',
                'id' => 'eventsCalendar-organizer',
            ],
        ]]]);

        $this->assertEmpty($doc->content, 'there should be no contacts');
    }

    public function testUnknownContactSectionIsAContact(): void
    {
        $doc = $this->enrich(['content' => ['items' => [
            $this->contactSection('eventsCalendar-other', [[
                'type' => 'contactPoint',
                'model' => ['organisation' => 'Kulturamt'],
            ]]),
        ]]]);

        $this->assertSection(
            'Kontakt',
            '<ul><li>Name: Kulturamt</li></ul>',
            $doc,
        );
    }

    public function testCategoriesOfTheContactSectionsAreAdded(): void
    {
        $doc = new GenAiDocument();
        $doc->categories = [new Category('1', 'Konzert')];
        $section = $this->contactSection('eventsCalendar-venue', []);
        $section['model'] = [
            'categories' => [['id' => 7, 'name' => 'Liederhalle'], ['id' => '1']],
            'categoriesPath' => [['id' => '5'], ['name' => 'no id'], 'no array'],
        ];
        $this->enricher->enrichDocument(
            $this->createResource(['content' => ['items' => [$section]]]),
            $doc,
            'process-id',
        );

        $this->assertEquals(
            [
                new Category('1', 'Konzert'),
                new Category('7', 'Liederhalle'),
                new Category('5', ''),
            ],
            $doc->categories,
            'the categories of the venue should be added once',
        );
    }

    public function testDatesComeBeforeTheContacts(): void
    {
        $doc = $this->enrich([
            'metadata' => ['scheduling' => [
                $this->date('2026-10-03 19:00', '2026-10-03 22:00'),
            ]],
            'content' => ['items' => [
                $this->contactSection('eventsCalendar-venue', [[
                    'type' => 'contactPoint',
                    'model' => ['organisation' => 'Liederhalle'],
                ]]),
            ]],
        ]);

        $this->assertEquals(
            ['Termine', 'Veranstaltungsort'],
            array_map(fn($section) => $section->headline, $doc->content),
            'unexpected order of the sections',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function date(string $from, ?string $to = null): array
    {
        return [
            'scheduleId' => 1,
            'scheduleType' => 'single',
            'contentType' => 'schedule schedule_single schedule_start'
                . ' schedule_end',
            'fullDay' => false,
            'from' => $this->timestamp($from),
            'to' => $this->timestamp($to ?? $from),
            'hasBeginTime' => true,
            'hasEndTime' => $to !== null,
            'status' => 'available',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function multi(string $part, string $from, string $to): array
    {
        return [
            'scheduleId' => 2,
            'scheduleType' => 'multi',
            'contentType' => 'schedule schedule_multi ' . $part,
            'fullDay' => false,
            'from' => $this->timestamp($from),
            'to' => $this->timestamp($to),
            'hasBeginTime' => $part === 'schedule_start',
            'hasEndTime' => $part === 'schedule_end',
            'status' => 'available',
        ];
    }

    private function timestamp(string $dateTime): int
    {
        return (new DateTimeImmutable(
            $dateTime,
            new DateTimeZone('Europe/Berlin'),
        ))->getTimestamp();
    }

    /**
     * @param array<mixed> $items
     * @return array<string,mixed>
     */
    private function contactSection(string $id, array $items): array
    {
        return [
            'type' => 'eventsCalendar.contactSection',
            'id' => $id,
            'items' => $items,
        ];
    }

    private function assertSection(
        string $headline,
        string $html,
        GenAiDocument $doc,
    ): void {
        $this->assertEquals(
            [new TextSection($headline, $html)],
            $doc->content,
            'unexpected sections',
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function enrich(
        array $data,
        string $objectType = 'eventsCalendar-event',
    ): GenAiDocument {
        $doc = new GenAiDocument();
        $this->enricher->enrichDocument(
            $this->createResource($data, $objectType),
            $doc,
            'process-id',
        );
        return $doc;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function createResource(
        array $data,
        string $objectType = 'eventsCalendar-event',
    ): Resource {
        return new Resource(
            '/veranstaltung.php',
            '123',
            'veranstaltung',
            $objectType,
            ResourceLanguage::default(),
            new DataBag($data),
        );
    }
}
