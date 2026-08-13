<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Exceptions\RecurrenceLimitExceeded;
use Mattmy\ICalendar\Facades\ICalendar;

it('bounds infinite recurrence expansion by the requested interval', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VEVENT
UID:infinite@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
DTEND:20260803T040000Z
RRULE:FREQ=DAILY
END:VEVENT
END:VCALENDAR
ICS);

    $occurrences = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 03:00:00 UTC'),
        CarbonImmutable::parse('2026-08-05 00:00:00 UTC'),
    );

    expect($occurrences)->toHaveCount(2)
        ->and($occurrences->first()?->startsAt?->toIso8601String())->toBe('2026-08-03T01:00:00+00:00')
        ->and($occurrences->last()?->startsAt?->toIso8601String())->toBe('2026-08-04T01:00:00+00:00');
});

it('counts recurrence candidates before EXDATE filtering', function () {
    $excluded = [];

    for ($day = 0; $day < 3501; $day++) {
        $excluded[] = CarbonImmutable::parse('2020-01-01 09:00:00 UTC')
            ->addDays($day)
            ->format('Ymd\THis\Z');
    }

    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "PRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:excluded@example.test\r\n"
        . "DTSTAMP:20200101T000000Z\r\n"
        . "DTSTART:20200101T090000Z\r\n"
        . "RRULE:FREQ=DAILY;COUNT=3502\r\n"
        . 'EXDATE:' . implode(',', $excluded) . "\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n");

    expect(fn () => $calendar->occurrencesBetween(
        CarbonImmutable::parse('2020-01-01 00:00:00 UTC'),
        CarbonImmutable::parse('2030-01-01 00:00:00 UTC'),
    ))->toThrow(RecurrenceLimitExceeded::class);
});

it('expands RDATE periods with their explicit durations', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VEVENT
UID:period@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
DTEND:20260803T020000Z
RRULE:FREQ=DAILY;COUNT=2
RDATE;VALUE=PERIOD:20260804T010000Z/20260804T040000Z,20260805T010000Z/PT2H,20260805T050000Z/+PT30M
END:VEVENT
END:VCALENDAR
ICS);

    $occurrences = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 00:00:00 UTC'),
        CarbonImmutable::parse('2026-08-06 00:00:00 UTC'),
    );

    expect($occurrences)->toHaveCount(4)
        ->and($occurrences->map(fn ($event): int => (int) ($event->endsAt?->diffInMinutes($event->startsAt, true) ?? 0))->all())
        ->toBe([60, 180, 120, 30]);
});

it('rejects invalid VEVENT and VTODO temporal relationships', function (string $component) {
    expect(fn () => ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n{$component}\r\nEND:VCALENDAR\r\n"))
        ->toThrow(InvalidCalendar::class);
})->with([
    'event end and duration' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nDTEND:20260803T020000Z\r\nDURATION:PT1H\r\nEND:VEVENT",
    'event mismatched value types' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART;VALUE=DATE:20260803\r\nDTEND:20260804T020000Z\r\nEND:VEVENT",
    'event end before start' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T020000Z\r\nDTEND:20260803T010000Z\r\nEND:VEVENT",
    'event negative duration' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nDURATION:-PT1H\r\nEND:VEVENT",
    'todo due and duration' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nDUE:20260803T020000Z\r\nDURATION:PT1H\r\nEND:VTODO",
    'todo duration without start' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDURATION:PT1H\r\nEND:VTODO",
    'todo due equal to start' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nDUE:20260803T010000Z\r\nEND:VTODO",
    'todo rule without start' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VTODO",
]);

it('validates VALARM action grammars and paired repeat fields', function (string $alarm) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\n"
        . $alarm
        . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class);
})->with([
    'display without description' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nEND:VALARM",
    'email without required fields' => "BEGIN:VALARM\r\nACTION:EMAIL\r\nTRIGGER:-PT5M\r\nEND:VALARM",
    'repeat without duration' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nREPEAT:2\r\nEND:VALARM",
    'duration without repeat' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nDURATION:PT1M\r\nEND:VALARM",
    'audio with multiple attachments' => "BEGIN:VALARM\r\nACTION:AUDIO\r\nTRIGGER:-PT5M\r\nATTACH:https://example.test/1.mp3\r\nATTACH:https://example.test/2.mp3\r\nEND:VALARM",
    'display with attachment' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nATTACH:https://example.test/file.txt\r\nEND:VALARM",
    'display with repeated description' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nDESCRIPTION:y\r\nEND:VALARM",
    'repeat must be positive' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:x\r\nREPEAT:0\r\nDURATION:PT1M\r\nEND:VALARM",
]);

it('accepts email alarm attachments and exposes the complete alarm properties', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VEVENT
UID:email-alarm@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
BEGIN:VALARM
ACTION:EMAIL
TRIGGER:-PT5M
DESCRIPTION:Body
SUMMARY:Subject
ATTENDEE:mailto:user@example.test
ATTACH:https://example.test/1.txt
ATTACH:https://example.test/2.txt
X-ALARM-ID:custom
END:VALARM
END:VEVENT
END:VCALENDAR
ICS);
    $alarm = $calendar->events()->sole()->alarms->sole();

    $raw = $alarm->rawComponent();
    $raw->SUMMARY = 'Changed';

    expect($alarm->attachments)->toHaveCount(2)
        ->and($alarm->properties('ATTACH'))->toHaveCount(2)
        ->and($alarm->property('X-ALARM-ID')?->value)->toBe('custom')
        ->and((string) $alarm->rawComponent()->SUMMARY)->toBe('Subject')
        ->and($calendar->toArray()['events'][0]['alarms'][0]['attachments'])->toHaveCount(2);
});

it('rejects invalid date-time forms and integers before normalization', function (string $property) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\n"
        . $property
        . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'floating timestamp' => 'DTSTAMP:20260801T000000',
    'floating created' => 'CREATED:20260801T000000',
    'TZID on date' => 'RDATE;VALUE=DATE;TZID=Asia/Taipei:20260804',
    'priority lexical form' => 'PRIORITY:abc',
    'priority range' => 'PRIORITY:10',
    'negative sequence' => 'SEQUENCE:-1',
    'integer above 32-bit range' => 'SEQUENCE:2147483648',
]);

it('rejects UTC-only and recurrence form violations in their valid component contexts', function (string $component) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . $component
        . "\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
})->with([
    'floating last modified' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nLAST-MODIFIED:20260801T000000\r\nEND:VEVENT",
    'floating completed' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nCOMPLETED:20260801T000000\r\nEND:VTODO",
    'percent complete range' => "BEGIN:VTODO\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nPERCENT-COMPLETE:101\r\nEND:VTODO",
    'floating absolute trigger' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:x\r\nTRIGGER;VALUE=DATE-TIME:20260803T005500\r\nEND:VALARM\r\nEND:VEVENT",
    'numeric UTC offset' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000+0800\r\nEND:VEVENT",
    'UNTIL form mismatch' => "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nRRULE:FREQ=DAILY;UNTIL=20260805T010000\r\nEND:VEVENT",
]);

it('uses a matching VTIMEZONE definition before host tzdata', function () {
    $event = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VTIMEZONE
TZID:Asia/Taipei
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0900
TZOFFSETTO:+0900
TZNAME:CUSTOM
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
UID:custom-zone@example.test
DTSTAMP:20260801T000000Z
DTSTART;TZID=Asia/Taipei:20260803T120000
END:VEVENT
END:VCALENDAR
ICS)->events()->sole();

    expect($event->startsAt?->format('Y-m-d H:i:s P'))->toBe('2026-08-03 12:00:00 +09:00');
});

it('accepts RFC INTEGER signs and leading zeroes without coercion loss', function () {
    $event = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VEVENT
UID:integer@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
PRIORITY:+01
SEQUENCE:0002
END:VEVENT
END:VCALENDAR
ICS)->events()->sole();

    expect($event->priority)->toBe(1)
        ->and($event->sequence)->toBe(2);
});

it('applies recurring VTIMEZONE observances from the calendar', function () {
    $events = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//RFC Hardening//EN
BEGIN:VTIMEZONE
TZID:Asia/Taipei
BEGIN:STANDARD
DTSTART:19701101T020000
RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU
TZOFFSETFROM:+1000
TZOFFSETTO:+0900
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:19700301T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=1SU
TZOFFSETFROM:+0900
TZOFFSETTO:+1000
END:DAYLIGHT
END:VTIMEZONE
BEGIN:VEVENT
UID:summer@example.test
DTSTAMP:20260801T000000Z
DTSTART;TZID=Asia/Taipei:20260803T120000
END:VEVENT
BEGIN:VEVENT
UID:winter@example.test
DTSTAMP:20260801T000000Z
DTSTART;TZID=Asia/Taipei:20261203T120000
END:VEVENT
END:VCALENDAR
ICS)->events();

    expect($events->first()?->startsAt?->format('P'))->toBe('+10:00')
        ->and($events->last()?->startsAt?->format('P'))->toBe('+09:00');
});

it('rejects invalid RDATE PERIOD values', function (string $period) {
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\n"
        . "RDATE;VALUE=PERIOD:{$period}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class);
})->with([
    'equal endpoints' => '20260804T010000Z/20260804T010000Z',
    'end before start' => '20260804T020000Z/20260804T010000Z',
    'negative duration' => '20260804T010000Z/-PT1H',
    'zero duration' => '20260804T010000Z/PT0S',
]);

it('rejects additional calendar objects instead of silently truncating them', function () {
    $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T010000Z\r\nEND:VEVENT\r\n"
        . "END:VCALENDAR\r\n";

    expect(fn () => ICalendar::read($calendar . $calendar))->toThrow(InvalidCalendar::class);
});

it('hydrates deeply nested extension components without quadratic cloning', function () {
    $depth = 200;
    $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//RFC Hardening//EN\r\n"
        . str_repeat("BEGIN:X-NEST\r\nX-VALUE:1\r\n", $depth)
        . str_repeat("END:X-NEST\r\n", $depth)
        . "END:VCALENDAR\r\n";

    memory_reset_peak_usage();
    $before = memory_get_usage(true);
    $calendar = ICalendar::read($contents);
    $growth = memory_get_peak_usage(true) - $before;

    expect($growth)->toBeLessThan(10 * 1024 * 1024)
        ->and($calendar->components('X-NEST'))->toHaveCount(1)
        ->and($calendar->components('X-NEST')->sole()->components('X-NEST'))->toHaveCount(1);
});
