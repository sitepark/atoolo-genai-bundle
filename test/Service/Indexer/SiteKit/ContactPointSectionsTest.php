<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Test\Service\Indexer\SiteKit;

use Atoolo\GenAi\Service\Indexer\SiteKit\ContactPointSections;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContactPointSections::class)]
class ContactPointSectionsTest extends TestCase
{
    private ContactPointSections $sections;

    public function setUp(): void
    {
        $this->sections = new ContactPointSections();
    }

    public function testTheOrganisationLeadsTheFacts(): void
    {
        $section = $this->sections->contact([
            'organisation' => 'Liederhalle',
            'organisationAffix' => 'Beethovensaal',
            'addressData' => [
                'street' => 'Berliner Platz',
                'housenumber' => '1',
                'postalCode' => '70174',
                'city' => 'Stuttgart',
            ],
        ]);

        $this->assertEquals(
            '<ul>'
            . '<li>Name: Liederhalle Beethovensaal</li>'
            . '<li>Adresse: Berliner Platz 1, 70174 Stuttgart</li>'
            . '</ul>',
            $section?->html,
            'the name of the organisation should come first',
        );
    }

    public function testAnOrganisationAloneIsAContact(): void
    {
        $section = $this->sections->contact(['organisation' => 'Theaterhaus']);

        $this->assertEquals(
            '<ul><li>Name: Theaterhaus</li></ul>',
            $section?->html,
            'unexpected contact section',
        );
    }

    public function testTheGivenHeadlineWins(): void
    {
        $section = $this->sections->contact(
            ['headline' => 'Kontakt', 'organisation' => 'Theaterhaus'],
            'Veranstaltungsort',
        );

        $this->assertEquals(
            'Veranstaltungsort',
            $section?->headline,
            'the given headline should replace the one of the contact point',
        );
    }

    public function testWithoutFactsThereIsNoContact(): void
    {
        $this->assertNull(
            $this->sections->contact(['headline' => 'Kontakt']),
            'a contact point without facts should be no section',
        );
    }

    public function testOpeningHoursWithTheGivenHeadline(): void
    {
        $section = $this->sections->openingHours(
            ['additionalText' => ['text' => '<p>Nach Vereinbarung.</p>']],
            'Öffnungszeiten (Veranstaltungsort)',
        );

        $this->assertEquals(
            'Öffnungszeiten (Veranstaltungsort)',
            $section?->headline,
            'unexpected headline',
        );
    }

    public function testPhoneNumberFromItsParts(): void
    {
        $this->assertEquals(
            '+49 711 216-0',
            $this->sections->phoneNumber([
                'countryCode' => '49',
                'areaCode' => '711',
                'localNumber' => '216',
                'extension' => '0',
            ]),
            'unexpected phone number',
        );
    }
}
