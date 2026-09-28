<?php

declare(strict_types=1);

namespace Atoolo\GenAi\Service\Indexer\SiteKit;

use Atoolo\GenAi\Dto\Indexer\TextSection;

/**
 * Turns a SiteKit contact point into the sections of a GenAI document.
 *
 * A contact point - phone number, address, how to get there, opening hours -
 * answers the questions people ask most. A resource carries it in
 * `metadata.contactPoint`, an event carries one for its venue, organizer and
 * ticket agency inside its content, so every enricher that meets one renders
 * it the same way.
 *
 * @phpstan-type Phone array{
 *     type?:string,
 *     nationalNumber?:string,
 *     internationalNumber?:string,
 *     countryCode?:string,
 *     areaCode?:string,
 *     localNumber?:string,
 *     extension?:string
 * }
 * @phpstan-type PhoneData array{phone?:Phone}
 * @phpstan-type Email array{email?:string}
 * @phpstan-type ContactData array{
 *     phoneList?:array<PhoneData>,
 *     emailList?:array<Email>,
 *     room?:string
 * }
 * @phpstan-type AddressData array{
 *     buildingName?:string,
 *     street?:string,
 *     housenumber?:string,
 *     postalCode?:string,
 *     city?:string,
 *     postOfficeBoxData?:array{buildingName?:string},
 *     notice?:string,
 *     publicTransportationNotice?:string,
 *     accessibleDescription?:string
 * }
 * @phpstan-type TimeRange array{start?:?string, end?:?string}
 * @phpstan-type WeekSeries array{
 *     dayOfWeekList?:array<string>,
 *     timeRangeList?:array<TimeRange>,
 *     notice?:?string
 * }
 * @phpstan-type WeekBlock array{
 *     headline?:?string,
 *     notice?:?string,
 *     weekSeriesList?:array<WeekSeries>
 * }
 * @phpstan-type OpeningHours array{
 *     weekBlockList?:array<WeekBlock>,
 *     additionalText?:?array{text?:?string}
 * }
 * @phpstan-type ContactPoint array{
 *     headline?:string,
 *     organisation?:string,
 *     organisationAffix?:string,
 *     contactData?:ContactData,
 *     addressData?:AddressData,
 *     openingHours?:OpeningHours
 * }
 */
class ContactPointSections
{
    private const WEEKDAYS = [
        'MONDAY' => 'Montag',
        'TUESDAY' => 'Dienstag',
        'WEDNESDAY' => 'Mittwoch',
        'THURSDAY' => 'Donnerstag',
        'FRIDAY' => 'Freitag',
        'SATURDAY' => 'Samstag',
        'SUNDAY' => 'Sonntag',
    ];

    /**
     * The facts go into one list, because a list is a single block to the
     * GenAI application and so is never torn apart by the chunking. The
     * notices follow as paragraphs of their own; they are prose and may well
     * end up in another chunk.
     *
     * @param ContactPoint $contactPoint
     * @param ?string $headline replaces the headline of the contact point,
     *     e.g. to say that it is the venue of an event
     */
    public function contact(
        array $contactPoint,
        ?string $headline = null,
    ): ?TextSection {
        $contactData = $contactPoint['contactData'] ?? [];
        $addressData = $contactPoint['addressData'] ?? [];

        $facts = [];
        $name = trim(
            trim($contactPoint['organisation'] ?? '')
            . ' ' . trim($contactPoint['organisationAffix'] ?? ''),
        );
        if ($name !== '') {
            $facts[] = 'Name: ' . $name;
        }
        foreach ($contactData['phoneList'] ?? [] as $entry) {
            $phone = $entry['phone'] ?? [];
            $number = $this->phoneNumber($phone);
            if ($number !== '') {
                $facts[] = $this->phoneLabel($phone) . ': ' . $number;
            }
        }
        foreach ($contactData['emailList'] ?? [] as $entry) {
            $email = trim($entry['email'] ?? '');
            if ($email !== '') {
                $facts[] = 'E-Mail: ' . $email;
            }
        }
        $address = $this->address($addressData);
        if ($address !== '') {
            $facts[] = 'Adresse: ' . $address;
        }
        $postOfficeBox = trim(
            $addressData['postOfficeBoxData']['buildingName'] ?? '',
        );
        if ($postOfficeBox !== '') {
            $facts[] = 'Postfach: ' . $postOfficeBox;
        }
        $room = trim($contactData['room'] ?? '');
        if ($room !== '') {
            $facts[] = 'Raum: ' . $room;
        }

        $notices = [];
        foreach (
            [
                'Hinweis' => $addressData['notice'] ?? '',
                'Anfahrt' => $addressData['publicTransportationNotice'] ?? '',
                'Barrierefreiheit'
                    => $addressData['accessibleDescription'] ?? '',
            ] as $label => $notice
        ) {
            $notice = trim($notice);
            if ($notice !== '') {
                $notices[] = $label . ': ' . $notice;
            }
        }

        if (empty($facts) && empty($notices)) {
            return null;
        }

        $html = '';
        if (!empty($facts)) {
            $html .= '<ul>';
            foreach ($facts as $fact) {
                $html .= '<li>' . $this->escape($fact) . '</li>';
            }
            $html .= '</ul>';
        }
        foreach ($notices as $notice) {
            $html .= '<p>' . $this->escape($notice) . '</p>';
        }

        $headline = trim($headline ?? $contactPoint['headline'] ?? '');
        return new TextSection(
            $headline !== '' ? $headline : 'Kontakt',
            $html,
        );
    }

    /**
     * The opening hours are a section of their own. Every week block becomes
     * one list of days, so the application never tears a week apart. A block
     * without headline and notice only continues the one before, as the
     * website shows it. The additional text is the editor's HTML.
     *
     * @param OpeningHours $openingHours
     */
    public function openingHours(
        array $openingHours,
        string $headline = 'Öffnungszeiten',
    ): ?TextSection {
        $html = '';
        $weekBlocks = $this->mergeWeekBlocks(
            $openingHours['weekBlockList'] ?? [],
        );
        foreach ($weekBlocks as $weekBlock) {
            $days = [];
            foreach ($weekBlock['weekSeriesList'] ?? [] as $weekSeries) {
                $days = array_merge($days, $this->weekDays($weekSeries));
            }
            if (empty($days)) {
                continue;
            }

            $blockHeadline = trim($weekBlock['headline'] ?? '');
            if ($blockHeadline !== '') {
                $html .= '<p>' . $this->escape($blockHeadline) . '</p>';
            }
            $html .= '<ul>';
            foreach ($days as $day) {
                $html .= '<li>' . $this->escape($day) . '</li>';
            }
            $html .= '</ul>';
            $notice = trim($weekBlock['notice'] ?? '');
            if ($notice !== '') {
                $html .= '<p>' . $this->escape($notice) . '</p>';
            }
        }

        $html .= trim($openingHours['additionalText']['text'] ?? '');

        return $html === ''
            ? null
            : new TextSection($headline, $html);
    }

    /**
     * SiteKit keeps the number in a readable form next to its parts, so the
     * parts are only assembled when it does not. The readable form already
     * ends with the extension.
     *
     * @param Phone $phone
     */
    public function phoneNumber(array $phone): string
    {
        foreach (['nationalNumber', 'internationalNumber'] as $name) {
            $number = trim($phone[$name] ?? '');
            if ($number !== '') {
                return $number;
            }
        }

        $countryCode = trim($phone['countryCode'] ?? '');
        $areaCode = trim($phone['areaCode'] ?? '');
        $localNumber = trim($phone['localNumber'] ?? '');
        if ($localNumber === '') {
            return '';
        }

        if ($countryCode !== '') {
            $number = '+' . $countryCode . ' ' . $areaCode;
        } elseif ($areaCode !== '') {
            $number = '0' . $areaCode;
        } else {
            $number = '';
        }

        return $this->withExtension(
            trim($number . ' ' . $localNumber),
            $phone,
        );
    }

    /**
     * @param array<WeekBlock> $weekBlockList
     * @return array<WeekBlock>
     */
    private function mergeWeekBlocks(array $weekBlockList): array
    {
        $merged = [];
        foreach ($weekBlockList as $weekBlock) {
            $last = array_key_last($merged);
            if (
                $last !== null
                && trim($weekBlock['headline'] ?? '') === ''
                && trim($weekBlock['notice'] ?? '') === ''
            ) {
                $merged[$last]['weekSeriesList'] = array_merge(
                    $merged[$last]['weekSeriesList'] ?? [],
                    $weekBlock['weekSeriesList'] ?? [],
                );
                continue;
            }
            $merged[] = $weekBlock;
        }
        return $merged;
    }

    /**
     * @param WeekSeries $weekSeries
     * @return string[]
     */
    private function weekDays(array $weekSeries): array
    {
        $ranges = [];
        foreach ($weekSeries['timeRangeList'] ?? [] as $timeRange) {
            $start = trim($timeRange['start'] ?? '');
            $end = trim($timeRange['end'] ?? '');
            if ($start !== '' && $end !== '') {
                $ranges[] = $start . ' - ' . $end . ' Uhr';
            } elseif ($start !== '' || $end !== '') {
                $ranges[] = ($start !== '' ? 'ab ' . $start : 'bis ' . $end)
                    . ' Uhr';
            }
        }
        $notice = trim($weekSeries['notice'] ?? '');
        if (empty($ranges) && $notice === '') {
            return [];
        }

        $text = implode(' und ', $ranges);
        if ($notice !== '') {
            $text = $text === '' ? $notice : $text . ' (' . $notice . ')';
        }

        $days = [];
        foreach ($weekSeries['dayOfWeekList'] ?? [] as $dayOfWeek) {
            $day = self::WEEKDAYS[strtoupper($dayOfWeek)] ?? null;
            if ($day !== null) {
                $days[] = $day . ': ' . $text;
            }
        }
        return $days;
    }

    /**
     * @param Phone $phone
     */
    private function withExtension(string $number, array $phone): string
    {
        $extension = trim($phone['extension'] ?? '');
        return $extension === '' ? $number : $number . '-' . $extension;
    }

    /**
     * @param Phone $phone
     */
    private function phoneLabel(array $phone): string
    {
        return strtolower($phone['type'] ?? '') === 'fax'
            ? 'Fax'
            : 'Telefon';
    }

    /**
     * @param AddressData $addressData
     */
    private function address(array $addressData): string
    {
        $street = trim(
            trim($addressData['street'] ?? '')
            . ' ' . trim($addressData['housenumber'] ?? ''),
        );
        $city = trim(
            trim($addressData['postalCode'] ?? '')
            . ' ' . trim($addressData['city'] ?? ''),
        );

        $parts = [];
        foreach (
            [
                trim($addressData['buildingName'] ?? ''),
                $street,
                $city,
            ] as $part
        ) {
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode(', ', $parts);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
