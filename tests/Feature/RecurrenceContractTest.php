<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Mattmy\ICalendar\Exceptions\RecurrenceLimitExceeded;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
use Mattmy\ICalendar\Facades\ICalendar;

it('includes a recurring point event at the lower boundary', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:point\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T090000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

    expect($calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 09:00 UTC'),
        CarbonImmutable::parse('2026-08-05 UTC'),
    )->pluck('startsAt')->map(fn ($date): string => $date->format('Y-m-d H:i'))->all())
        ->toBe(['2026-08-03 09:00', '2026-08-04 09:00']);
});

it('expands unordered RDATE inclusions before applying the upper boundary', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:rdate\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T090000Z\r\nRDATE:20260810T090000Z,20260804T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

    expect($calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 UTC'),
        CarbonImmutable::parse('2026-08-05 UTC'),
    )->pluck('startsAt')->map(fn ($date): string => $date->format('Y-m-d H:i'))->all())
        ->toBe(['2026-08-03 09:00', '2026-08-04 09:00']);
});

it('rejects recurrence parts that the underlying engine silently ignores', function (string $rule) {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:unsupported\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T090000Z\r\nRRULE:{$rule}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

    expect(fn () => $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 UTC'),
        CarbonImmutable::parse('2026-08-05 UTC'),
    ))->toThrow(UnsupportedRecurrence::class);
})->with([
    'secondly' => 'FREQ=SECONDLY;COUNT=3',
    'minutely' => 'FREQ=MINUTELY;COUNT=3',
    'seconds' => 'FREQ=DAILY;COUNT=3;BYSECOND=30',
    'minutes' => 'FREQ=DAILY;COUNT=3;BYMINUTE=30',
]);

it('uses calendar observances and UTC UNTIL while expanding local recurrence', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Tests//EN
BEGIN:VTIMEZONE
TZID:Asia/Taipei
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0500
TZOFFSETTO:+0500
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
UID:local
DTSTAMP:20260801T000000Z
DTSTART;TZID=Asia/Taipei:20260803T120000
DTEND;TZID=Asia/Taipei:20260803T130000
RRULE:FREQ=DAILY;UNTIL=20260804T070000Z
RDATE;TZID=Asia/Taipei:20260805T120000
EXDATE;TZID=Asia/Taipei:20260804T120000
END:VEVENT
END:VCALENDAR
ICS);

    $occurrences = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 06:30 UTC'),
        CarbonImmutable::parse('2026-08-06 UTC'),
    );

    expect($occurrences->pluck('startsAt')->map(fn ($date): string => $date->toIso8601String())->all())
        ->toBe(['2026-08-03T12:00:00+05:00', '2026-08-05T12:00:00+05:00'])
        ->and($occurrences->map(fn ($event): int => $event->endsAt->getTimestamp() - $event->startsAt->getTimestamp())->all())
        ->toBe([3600, 3600]);
});

it('allows exactly the documented number of recurrence candidates', function (int $count) {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:limit\r\nDTSTAMP:20200101T000000Z\r\nDTSTART:20200101T090000Z\r\nRRULE:FREQ=DAILY;COUNT={$count}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
    $query = fn () => $calendar->occurrencesBetween(
        CarbonImmutable::parse('2020-01-01 UTC'),
        CarbonImmutable::parse('2030-01-01 UTC'),
    );

    if ($count === 3500) {
        expect($query())->toHaveCount(3500);
    } else {
        expect($query)->toThrow(RecurrenceLimitExceeded::class);
    }
})->with([3500, 3501]);

it('preserves the exact DTEND duration across a calendar observance transition', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Tests//EN
BEGIN:VTIMEZONE
TZID:Custom/Transition
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0500
TZOFFSETTO:+0500
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:20260804T020000
TZOFFSETFROM:+0500
TZOFFSETTO:+0600
END:DAYLIGHT
END:VTIMEZONE
BEGIN:VEVENT
UID:transition
DTSTAMP:20260801T000000Z
DTSTART;TZID=Custom/Transition:20260803T010000
DTEND;TZID=Custom/Transition:20260803T030000
RRULE:FREQ=DAILY;COUNT=2
END:VEVENT
END:VCALENDAR
ICS);

    $occurrences = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-02 UTC'),
        CarbonImmutable::parse('2026-08-05 UTC'),
    );

    expect($occurrences)->toHaveCount(2)
        ->and($occurrences->map(fn ($event): int => $event->endsAt->getTimestamp() - $event->startsAt->getTimestamp())->all())
        ->toBe([7200, 7200]);
});

it('uses the effective duration and start of overrides when checking overlap', function (string $override, string $from, string $until, array $expected) {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//EN\r\nBEGIN:VEVENT\r\nUID:override\r\nDTSTAMP:20260801T000000Z\r\nDTSTART:20260803T090000Z\r\nDURATION:PT1H\r\nRRULE:FREQ=DAILY;COUNT=2\r\nSUMMARY:Master\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:override\r\nDTSTAMP:20260801T000000Z\r\nRECURRENCE-ID:20260804T090000Z\r\n{$override}\r\nSUMMARY:Override\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

    expect($calendar->occurrencesBetween(
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($until),
    )->pluck('summary')->all())->toBe($expected);
})->with([
    'long duration crosses lower boundary' => [
        "DTSTART:20260804T090000Z\r\nDURATION:PT5H",
        '2026-08-04 12:00 UTC', '2026-08-04 13:00 UTC', ['Override'],
    ],
    'moved into range from future slot' => [
        "DTSTART:20260802T090000Z\r\nDURATION:PT1H",
        '2026-08-02 UTC', '2026-08-03 UTC', ['Override'],
    ],
    'moved out of original slot' => [
        "DTSTART:20260810T090000Z\r\nDURATION:PT1H",
        '2026-08-04 UTC', '2026-08-05 UTC', [],
    ],
]);
