<?php

declare(strict_types=1);

use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Facades\ICalendar;

it('rejects normalized or malformed temporal values before hydration', function (string $property) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20261001T000000Z\r\n"
        . $property . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'invalid DATE' => 'DTSTART;VALUE=DATE:20260230',
    'invalid month' => 'DTSTART:20260001T120000Z',
    'invalid day' => 'DTSTART:20261000T120000Z',
    'invalid DATE-TIME' => 'DTSTART:20260230T120000Z',
    'invalid hour' => 'DTSTART:20261001T240000Z',
    'invalid minute' => 'DTSTART:20261001T126000Z',
    'invalid UNTIL' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;UNTIL=20260230T120000Z",
    'invalid duration unit' => "DTSTART:20261001T120000Z\r\nDURATION:PT1X",
    'mixed weeks and days' => "DTSTART:20261001T120000Z\r\nDURATION:P1W1D",
    'unfinished day duration' => "DTSTART:20261001T120000Z\r\nDURATION:P1DT",
    'empty trigger duration' => "DTSTART:20261001T120000Z\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-P\r\nDESCRIPTION:x\r\nEND:VALARM",
    'invalid trigger duration' => "DTSTART:20261001T120000Z\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT1X\r\nDESCRIPTION:x\r\nEND:VALARM",
    'invalid PERIOD date' => "DTSTART:20261001T120000Z\r\nRDATE;VALUE=PERIOD:20260230T120000Z/PT1H",
    'invalid PERIOD end' => "DTSTART:20261001T120000Z\r\nRDATE;VALUE=PERIOD:20261001T120000Z/20261001T240000Z",
    'invalid PERIOD duration' => "DTSTART:20261001T120000Z\r\nRDATE;VALUE=PERIOD:20261001T120000Z/P1W1D",
    'text DTSTART' => 'DTSTART;VALUE=TEXT:20261001T120000Z',
    'integer DTSTART' => 'DTSTART;VALUE=INTEGER:1',
    'text DURATION' => "DTSTART:20261001T120000Z\r\nDURATION;VALUE=TEXT:P1D",
    'multiple durations' => "DTSTART:20261001T120000Z\r\nDURATION:PT1H,PT2H",
    'multiple start values' => 'DTSTART:20261001T120000Z,20261002T120000Z',
    'invalid interval' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;INTERVAL=abc",
    'zero interval' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;INTERVAL=0",
    'fractional count' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;COUNT=1.5",
    'zero count' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;COUNT=0",
    'count with until' => "DTSTART:20261001T120000Z\r\nRRULE:FREQ=DAILY;COUNT=1;UNTIL=20261002T120000Z",
]);

it('requires one complete calendar without ignored trailing data', function (string $suffix, bool $removeEnd) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20261001T000000Z\r\nDTSTART:20261001T120000Z\r\nEND:VEVENT\r\n"
        . ($removeEnd ? '' : "END:VCALENDAR\r\n") . $suffix;

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'missing calendar end' => ['', true],
    'trailing text' => ['ignored text', false],
    'trailing property' => ["X-DATA:ignored\r\n", false],
    'trailing component' => ["BEGIN:VCARD\r\nVERSION:3.0\r\nFN:x\r\nEND:VCARD\r\n", false],
    'duplicate calendar end' => ["END:VCALENDAR\r\n", false],
]);

it('validates typed extension properties on the calendar root', function () {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "X-NUMBER;VALUE=INTEGER:abc\r\nBEGIN:X-COMPONENT\r\nEND:X-COMPONENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
});

it('preserves valid folded extension data and RFC duration forms', function (string $duration) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20261001T000000Z\r\nDTSTART:20261001T120000Z\r\n"
        . "DURATION:{$duration}\r\nX-DATA:prefix\r\n BEGIN:VCALENDAR\r\n END:VCALENDAR\r\n"
        . "END:VEVENT\r\nEND:VCALENDAR\r\n\r\n";

    $event = ICalendar::read($contents)->event('x');

    expect($event?->duration)->not->toBeNull()
        ->and($event?->property('X-DATA')?->value)->toBe('prefixBEGIN:VCALENDAREND:VCALENDAR');
})->with(['P1W', 'P2D', 'P2DT3H4M5S', 'PT1H', 'PT30M', '+PT1S']);

it('compares calendar-defined and floating temporal values as they are hydrated', function (string $start, string $end, bool $isValid) {
    config()->set('icalendar_reader.floating_timezone', 'Asia/Taipei');
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Validation//EN\r\n"
        . "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\n"
        . "TZOFFSETFROM:+0200\r\nTZOFFSETTO:+0200\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20261001T000000Z\r\n{$start}\r\n{$end}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    if (! $isValid) {
        expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
            ->and(ICalendar::tryRead($contents))->toBeNull();

        return;
    }

    $event = ICalendar::read($contents)->event('x');

    expect($event?->endsAt?->greaterThan($event->startsAt))->toBeTrue();
})->with([
    'calendar timezone allows valid end' => ['DTSTART;TZID=America/New_York:20261001T120000', 'DTEND:20261001T110000Z', true],
    'calendar timezone rejects earlier end' => ['DTSTART:20261001T110000Z', 'DTEND;TZID=America/New_York:20261001T120000', false],
    'floating timezone allows valid end' => ['DTSTART:20261001T120000', 'DTEND:20261001T050000Z', true],
    'floating timezone rejects earlier end' => ['DTSTART:20261001T050000Z', 'DTEND:20261001T120000', false],
]);
