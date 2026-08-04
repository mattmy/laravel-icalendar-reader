<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\Exceptions\CalendarTooLarge;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Exceptions\InvalidConfiguration;
use Mattmy\ICalendar\Facades\ICalendar;
use Sabre\VObject\ParseException;

it('reads and validates a calendar through the facade', function () {
    $calendar = ICalendar::read(calendarFixture('basic-event'));
    $event = $calendar->events()->first();

    expect($calendar)
        ->toBeInstanceOf(Calendar::class)
        ->and($calendar->productId)->toBe('-//Mattmy//Laravel iCalendar Reader Tests//EN')
        ->and($calendar->floatingTimezone)->toBe('Asia/Taipei')
        ->and($calendar->hasEvents())->toBeTrue()
        ->and($calendar->hasEvents('Architecture review'))->toBeTrue()
        ->and($calendar->hasEvents('architecture review'))->toBeFalse()
        ->and($calendar->hasEvents(''))->toBeFalse()
        ->and($event->uid)->toBe('architecture-review@example.test')
        ->and($event->summary)->toBe('Architecture review')
        ->and($event->startsAt)->toEqual(CarbonImmutable::parse('2026-08-03 06:30:00', 'UTC'))
        ->and($event->allDay)->toBeFalse()
        ->and($event->isAllDay())->toBe($event->allDay);
});

it('identifies all-day events from the DTSTART value type', function () {
    $event = ICalendar::read(calendarFixture('all-day-event'))->events()->sole();

    expect($event->allDay)->toBeTrue()
        ->and($event->isAllDay())->toBe($event->allDay)
        ->and($event->startsAt?->timezoneName)->toBe('Asia/Taipei')
        ->and($event->startsAt?->toDateString())->toBe('2026-08-03');
});

it('filters events by exact case-sensitive summary in document order', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Event Filtering Tests//EN
BEGIN:VEVENT
UID:first@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
SUMMARY:Repeated name
END:VEVENT
BEGIN:VEVENT
UID:second@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T020000Z
SUMMARY:Repeated name
END:VEVENT
BEGIN:VEVENT
UID:case@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T030000Z
SUMMARY:repeated name
END:VEVENT
BEGIN:VEVENT
UID:missing@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T040000Z
END:VEVENT
END:VCALENDAR
ICS);

    expect($calendar->events())->toHaveCount(4)
        ->and($calendar->events(null))->toHaveCount(4)
        ->and($calendar->events('Repeated name')->pluck('uid')->all())->toBe([
            'first@example.test',
            'second@example.test',
        ])
        ->and($calendar->events('repeated name')->sole()->uid)->toBe('case@example.test')
        ->and($calendar->events('Missing name'))->toBeEmpty()
        ->and($calendar->events(''))->toBeEmpty()
        ->and($calendar->hasEvents('Repeated name'))->toBeTrue()
        ->and($calendar->hasEvents('Missing name'))->toBeFalse();
});

it('throws structured invalid calendar errors for syntax failures', function () {
    try {
        ICalendar::read("BEGIN:VCALENDAR\r\nBROKEN");
    } catch (InvalidCalendar $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(ParseException::class)
            ->and($exception->issues())->toHaveCount(1)
            ->and($exception->issues()->sole()->code)->toBe('parser_error')
            ->and($exception->issues()->sole()->source)->toBe('parser');

        return;
    }

    $this->fail('Expected InvalidCalendar to be thrown.');
});

it('rejects a non-calendar root with a stable issue', function () {
    try {
        ICalendar::read(<<<'VCF'
BEGIN:VCARD
VERSION:4.0
FN:Example Person
N:Person;Example;;;
END:VCARD
VCF);
    } catch (InvalidCalendar $exception) {
        expect($exception->issues()->sole()->code)->toBe('invalid_root_component')
            ->and($exception->issues()->sole()->source)->toBe('parser');

        return;
    }

    $this->fail('Expected InvalidCalendar to be thrown.');
});

it('maps level three validation failures and keeps nullable behavior explicit', function () {
    $contents = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:missing-dtstamp@example.test
DTSTART:20260803T010000Z
END:VEVENT
END:VCALENDAR
ICS;

    try {
        ICalendar::read($contents);
    } catch (InvalidCalendar $exception) {
        expect($exception->issues()->sole()->level)->toBe(3)
            ->and($exception->issues()->sole()->code)->toBe('validation_error')
            ->and($exception->issues()->sole()->source)->toBe('validator')
            ->and(ICalendar::tryRead($contents))->toBeNull();

        return;
    }

    $this->fail('Expected InvalidCalendar to be thrown.');
});

it('returns null only for invalid calendar contents', function () {
    expect(ICalendar::tryRead('not a calendar'))->toBeNull();

    config()->set('icalendar_reader.max_bytes', 1);

    expect(fn () => ICalendar::tryRead(calendarFixture('basic-event')))
        ->toThrow(CalendarTooLarge::class);
});

it('rejects invalid size configuration before parsing', function (mixed $value) {
    config()->set('icalendar_reader.max_bytes', $value);

    expect(fn () => ICalendar::read(calendarFixture('basic-event')))
        ->toThrow(InvalidConfiguration::class);
})->with([
    'null' => null,
    'string' => '1024',
    'zero' => 0,
    'negative' => -1,
]);

it('uses UTC and records a warning for invalid application timezone', function () {
    config()->set('app.timezone', 'Not/A_Timezone');

    $calendar = ICalendar::read(calendarFixture('all-day-event'));

    expect($calendar->floatingTimezone)->toBe('UTC')
        ->and($calendar->warnings())->toHaveCount(1)
        ->and($calendar->warnings()->sole()->code)->toBe('invalid_timezone_configuration');
});

it('returns defensive clones of raw Sabre components', function () {
    $calendar = ICalendar::read(calendarFixture('basic-event'));
    $raw = $calendar->rawComponent();
    $raw->VERSION = '9.9';

    expect((string) $calendar->rawComponent()->VERSION)->toBe('2.0')
        ->and($calendar->version)->toBe('2.0');
});
