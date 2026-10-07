<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Exceptions\ICalendarException;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Exceptions\UnresolvableEventRange;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
use Mattmy\ICalendar\Facades\ICalendar;

/** Read event and todo counterparts against the same calendar-defined timezone. */
function durationCalendar(string $start, string $properties, ?string $observances = null): Calendar
{
    $observances ??= "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0500\nEND:STANDARD\n"
        . "BEGIN:DAYLIGHT\nDTSTART:20260308T020000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0400\nEND:DAYLIGHT\n"
        . "BEGIN:STANDARD\nDTSTART:20261101T020000\nTZOFFSETFROM:-0400\nTZOFFSETTO:-0500\nEND:STANDARD\n";
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Duration//EN\n"
        . "BEGIN:VTIMEZONE\nTZID:America/New_York\n{$observances}END:VTIMEZONE\n";
    foreach (['VEVENT', 'VTODO'] as $name) {
        $contents .= "BEGIN:{$name}\nUID:{$name}-duration\nDTSTAMP:20260101T000000Z\nDTSTART;TZID=America/New_York:{$start}\n"
            . ($name === 'VTODO' ? \str_replace('DTEND', 'DUE', $properties) : $properties) . "END:{$name}\n";
    }

    return ICalendar::read($contents . "END:VCALENDAR\n");
}

it('derives nominal days and accurate hours using calendar observances for events and todos', function (string $start, string $duration, string $expected, int $hours) {
    $calendar = durationCalendar($start, "DURATION:{$duration}\n");
    $event = $calendar->events()->sole();
    $todo = $calendar->todos()->sole();

    expect($event->endsAt?->format('Y-m-d H:i:s P'))->toBe($expected)
        ->and($todo->dueAt?->format('Y-m-d H:i:s P'))->toBe($expected);
    expect(($event->endsAt?->getTimestamp() ?? 0) - ($event->startsAt?->getTimestamp() ?? 0))->toBe($hours * 3600);
    expect($event->property('DURATION')?->rawValue())->toBe($duration);
    expect($calendar->toArray()['events'][0]['ends_at'])->toBe($event->endsAt?->toIso8601String());
    expect($calendar->toArray()['todos'][0]['due_at'])->toBe($todo->dueAt?->toIso8601String());
})->with([
    ['20260307T090000', 'P1D', '2026-03-08 09:00:00 -04:00', 23],
    ['20260307T090000', 'PT24H', '2026-03-08 10:00:00 -04:00', 24],
    ['20260301T090000', 'P1W', '2026-03-08 09:00:00 -04:00', 167],
    ['20260307T090000', 'P1DT2H', '2026-03-08 11:00:00 -04:00', 25],
    ['20261031T090000', 'P1D', '2026-11-01 09:00:00 -05:00', 25],
    ['20261031T090000', 'PT24H', '2026-11-01 08:00:00 -05:00', 24],
    ['20261025T090000', 'P1W', '2026-11-01 09:00:00 -05:00', 169],
    ['20261031T090000', 'P1DT2H', '2026-11-01 11:00:00 -05:00', 27],
    ['20260307T023000', 'P1D', '2026-03-08 02:30:00 -05:00', 24],
    ['20261031T013000', 'P1D', '2026-11-01 01:30:00 -04:00', 24],
]);

it('keeps a calendar fixed offset authoritative over same named host DST for long durations', function () {
    $calendar = durationCalendar(
        '20260801T090000',
        "DURATION:P100D\n",
        "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0400\nTZOFFSETTO:-0400\nEND:STANDARD\n",
    );
    expect($calendar->events()->sole()->endsAt?->format('Y-m-d H:i P'))->toBe('2026-11-09 09:00 -04:00')
        ->and($calendar->todos()->sole()->dueAt?->format('Y-m-d H:i P'))->toBe('2026-11-09 09:00 -04:00');
});

it('preserves calendar wall clocks when a same-offset host zone normalizes a gap', function (string $start, string $expectedStart, string $expectedEnd) {
    $calendar = durationCalendar(
        $start,
        "DURATION:P1D\n",
        "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0400\nTZOFFSETTO:-0400\nEND:STANDARD\n",
    );
    $event = $calendar->events()->sole();
    $todo = $calendar->todos()->sole();

    expect($event->startsAt?->toIso8601String())->toBe($expectedStart);
    expect($todo->startsAt?->toIso8601String())->toBe($expectedStart);
    expect($event->endsAt?->toIso8601String())->toBe($expectedEnd);
    expect($todo->dueAt?->toIso8601String())->toBe($expectedEnd);
    expect($event->property('DTSTART')?->value)->toEqual($event->startsAt);
    expect($calendar->component('VEVENT')?->property('DTSTART')?->value)->toEqual($event->startsAt);
    expect($calendar->toArray()['events'][0]['starts_at'])->toBe($expectedStart);
    expect($calendar->warnings()->where('code', 'mapping_warning'))->toBeEmpty();
})->with([
    'source in host gap' => ['20260308T023000', '2026-03-08T02:30:00-04:00', '2026-03-09T02:30:00-04:00'],
    'derived end in host gap' => ['20260307T023000', '2026-03-07T02:30:00-04:00', '2026-03-08T02:30:00-04:00'],
    'matching host wall clock' => ['20260308T033000', '2026-03-08T03:30:00-04:00', '2026-03-09T03:30:00-04:00'],
]);

it('compares explicit endpoints using calendar wall clocks across a host gap', function () {
    $calendar = durationCalendar(
        '20260308T023000',
        "DTEND;TZID=America/New_York:20260308T030000\n",
        "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0400\nTZOFFSETTO:-0400\nEND:STANDARD\n",
    );

    expect($calendar->events()->sole()->endsAt?->toIso8601String())->toBe('2026-03-08T03:00:00-04:00');
    expect($calendar->todos()->sole()->dueAt?->toIso8601String())->toBe('2026-03-08T03:00:00-04:00');
    expect(ICalendar::tryRead($calendar->rawComponent()->serialize()))->not->toBeNull();
});

it('preserves a resolved start and duration when the derived endpoint becomes unsupported', function (string $duration) {
    $calendar = durationCalendar(
        '20260307T090000',
        "DURATION:{$duration}\n",
        "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0500\nEND:STANDARD\n"
        . "BEGIN:DAYLIGHT\nDTSTART:20260308T020000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-040030\nEND:DAYLIGHT\n",
    );
    expect($calendar->events()->sole()->startsAt)->not->toBeNull()
        ->and($calendar->events()->sole()->endsAt)->toBeNull();
    expect($calendar->todos()->sole()->dueAt)->toBeNull();
    expect($calendar->events()->sole()->duration)->toBeInstanceOf(DateInterval::class);
    expect($calendar->warnings()->where('code', 'mapping_warning')->where('property', 'DURATION'))->toHaveCount(2);
    expect($calendar->toArray()['events'][0]['ends_at'])->toBeNull();
    expect($calendar->component('VEVENT')?->property('DURATION')?->rawValue())->toBe($duration);
    foreach ([-1, 0, 1] as $hours) {
        $from = $calendar->events()->sole()->startsAt?->addHours($hours);
        if ($from === null) {
            throw new RuntimeException('The fixture start must resolve.');
        }
        expect(fn () => $calendar->eventsBetween($from, $from->addDays(2)))->toThrow(UnresolvableEventRange::class);
    }
    expect($calendar->eventsBetween(CarbonImmutable::parse('2026-03-06 UTC'), CarbonImmutable::parse('2026-03-07 14:00 UTC')))->toBeEmpty();
})->with(['P1D', 'PT24H', 'P1DT2H']);

it('preserves DATE UTC and floating duration forms and explicit endpoints', function (string $start, string $properties, string $expected) {
    config(['icalendar_reader.floating_timezone' => 'America/New_York']);
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Forms//EN\n"
        . "BEGIN:VEVENT\nUID:forms\nDTSTAMP:20260101T000000Z\n{$start}\n{$properties}\nEND:VEVENT\nEND:VCALENDAR\n";
    $calendar = ICalendar::read($contents);
    expect($calendar->events()->sole()->endsAt?->format('Y-m-d H:i:s P'))->toBe($expected);
})->with([
    ['DTSTART;VALUE=DATE:20260307', 'DURATION:P1W', '2026-03-14 00:00:00 -04:00'],
    ['DTSTART;VALUE=DATE:20260307', 'DTEND;VALUE=DATE:20260310', '2026-03-10 00:00:00 -04:00'],
    ['DTSTART:20260307T090000Z', 'DURATION:P1DT2H', '2026-03-08 11:00:00 +00:00'],
    ['DTSTART:20260307T090000', 'DURATION:P1D', '2026-03-08 09:00:00 -04:00'],
    ['DTSTART:20260307T090000', 'DURATION:PT24H', '2026-03-08 10:00:00 -04:00'],
    ['DTSTART:20260307T090000Z', 'DTEND:20260308T100000Z', '2026-03-08 10:00:00 +00:00'],
]);

it('fails concrete range queries on required unresolved ends without changing the calendar', function (string $end, int $fromHour) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Range//EN\n"
        . "BEGIN:VEVENT\nUID:point\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\nEND:VEVENT\n"
        . "BEGIN:VEVENT\nUID:unresolved\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\n{$end}\nEND:VEVENT\nEND:VCALENDAR\n";
    $calendar = ICalendar::read($contents);
    $before = [$calendar->toJson(), $calendar->rawComponent()->serialize()];
    $from = CarbonImmutable::parse('2026-01-01 UTC')->addHours($fromHour);
    $until = CarbonImmutable::parse('2026-01-02 UTC');
    expect(ICalendar::tryRead($contents))->not->toBeNull();
    expect(fn () => $calendar->eventsBetween($from, $until))->toThrow(UnresolvableEventRange::class);
    expect(fn () => $calendar->eventsBetween($until, $from))->toThrow(InvalidArgumentException::class);
    expect([$calendar->toJson(), $calendar->rawComponent()->serialize()])->toBe($before);
    expect($calendar->eventsBetween(CarbonImmutable::parse('2026-01-01 08:00 UTC'), CarbonImmutable::parse('2026-01-01 09:00 UTC')))->toBeEmpty();
})->with(['DTEND;TZID=Unknown:20260102T090000'])->with([[8], [9], [10]]);

it('exposes the concrete range failure through the package exception hierarchy', function () {
    $exception = new UnresolvableEventRange('Unresolved end.');
    expect($exception)->toBeInstanceOf(RuntimeException::class)->toBeInstanceOf(ICalendarException::class);
});

it('fails non-recurring occurrence ranges on required unresolved endpoints', function (string $kind, int $relation) {
    $calendar = match ($kind) {
        'duration' => durationCalendar('20260307T090000', "DURATION:P1D\n", "BEGIN:STANDARD\nDTSTART:20000101T000000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0500\nEND:STANDARD\nBEGIN:DAYLIGHT\nDTSTART:20260308T020000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-040030\nEND:DAYLIGHT\n"),
        'explicit' => ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//D2//EN\nBEGIN:VEVENT\nUID:d2\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\nDTEND;TZID=Unknown:20260102T090000\nEND:VEVENT\nEND:VCALENDAR\n"),
        'date' => ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//D2//EN\nBEGIN:VEVENT\nUID:d2\nDTSTAMP:20260101T000000Z\nDTSTART;VALUE=DATE:99991231\nEND:VEVENT\nEND:VCALENDAR\n"),
        default => throw new InvalidArgumentException('Unknown endpoint fixture.'),
    };
    $start = $calendar->events()->sole()->startsAt ?? throw new RuntimeException('Fixture start must resolve.');
    $from = $start->addHours($relation);
    $until = $start->addDays(2);
    $before = [$calendar->toJson(), $calendar->rawComponent()->serialize()];
    expect(ICalendar::tryRead($calendar->rawComponent()->serialize()))->not->toBeNull();
    expect(fn () => $calendar->occurrencesBetween($from, $until))->toThrow(UnresolvableEventRange::class);
    expect(fn () => $calendar->occurrencesBetween($until, $from))->toThrow(InvalidArgumentException::class);
    expect([$calendar->toJson(), $calendar->rawComponent()->serialize()])->toBe($before);
    expect($calendar->occurrencesBetween($start->subHours(2), $start))->toBeEmpty();
    expect($calendar->occurrencesBetween($start->subHours(3), $start->subHour()))->toBeEmpty();
})->with(['explicit', 'duration', 'date'])->with([[-1], [0], [1]]);

it('keeps non-recurring points and overlaps while excluding unresolved starts', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//D2//EN\n"
        . "BEGIN:VEVENT\nUID:point\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\nEND:VEVENT\n"
        . "BEGIN:VEVENT\nUID:span\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T080000Z\nDTEND:20260101T100000Z\nEND:VEVENT\n"
        . "BEGIN:VEVENT\nUID:unknown\nDTSTAMP:20260101T000000Z\nDTSTART;TZID=Unknown:20260101T090000\nDTEND;TZID=Unknown:20260102T090000\nEND:VEVENT\nEND:VCALENDAR\n");
    $start = CarbonImmutable::parse('2026-01-01 09:00 UTC');
    expect($calendar->occurrencesBetween($start, $start->addHour())->pluck('uid')->all())->toBe(['span', 'point']);
    expect($calendar->occurrencesBetween($start->subHour(), $start)->pluck('uid')->all())->toBe(['span']);
});

it('does not return a partial non-recurring occurrence list after an unresolved endpoint', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//D2//EN\n"
        . "BEGIN:VEVENT\nUID:point\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\nEND:VEVENT\n"
        . "BEGIN:VEVENT\nUID:unknown\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\nDTEND;TZID=Unknown:20260102T090000\nEND:VEVENT\nEND:VCALENDAR\n");
    $from = CarbonImmutable::parse('2026-01-01 09:00 UTC');
    $before = $calendar->toJson();
    expect(fn () => $calendar->occurrencesBetween($from, $from->addDay()))->toThrow(UnresolvableEventRange::class);
    expect($calendar->toJson())->toBe($before);
    expect($calendar->occurrencesBetween($from->subHour(), $from))->toBeEmpty();
});

it('fails on an unresolved implicit DATE end at the RFC date boundary but still reads the source', function (int $days) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Date Boundary//EN\n"
        . "BEGIN:VEVENT\nUID:date\nDTSTAMP:20260101T000000Z\nDTSTART;VALUE=DATE:99991231\nEND:VEVENT\nEND:VCALENDAR\n";
    $calendar = ICalendar::read($contents);
    $event = $calendar->events()->sole();
    $from = CarbonImmutable::parse('9999-12-31', 'Asia/Taipei')->addDays($days);

    expect(ICalendar::tryRead($contents))->not->toBeNull();
    expect($event->endsAt)->toBeNull()->and($event->lastDay)->toBeNull();
    expect($event->endIsDate)->toBeTrue()->and($event->endIsFloating)->toBeTrue();
    expect($event->duration?->d)->toBe(1);
    expect($calendar->warnings()->where('code', 'mapping_warning'))->not->toBeEmpty();
    expect(fn () => $calendar->eventsBetween($from, $from->addDays(3)))->toThrow(UnresolvableEventRange::class);
})->with([[-1], [0], [1]]);

it('derives an exclusive one-day end for an all-day event without an explicit end', function () {
    $event = ICalendar::read(calendarFixture('all-day-event'))->events()->sole();

    expect($event->startIsFloating)->toBeTrue()
        ->and($event->endIsFloating)->toBeTrue()
        ->and($event->startIsDate)->toBeTrue()
        ->and($event->endIsDate)->toBeTrue()
        ->and($event->endsAt?->toDateString())->toBe('2026-08-04')
        ->and($event->lastDay?->toDateString())->toBe('2026-08-03')
        ->and($event->duration?->d)->toBe(1);
});

it('marks floating date-times without guessing that midnight means all day', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:floating@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T000000
DTEND:20260803T010000
SUMMARY:Floating midnight
END:VEVENT
END:VCALENDAR
ICS);
    $event = $calendar->events()->sole();

    expect($event->allDay)->toBeFalse()
        ->and($event->isAllDay())->toBe($event->allDay)
        ->and($event->startIsDate)->toBeFalse()
        ->and($event->endIsDate)->toBeFalse()
        ->and($event->startIsFloating)->toBeTrue()
        ->and($event->endIsFloating)->toBeTrue()
        ->and($event->startsAt?->timezoneName)->toBe('Asia/Taipei');
});

it('uses half-open overlap rules for bounded and zero-length events', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:bounded@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T010000Z
DTEND:20260803T020000Z
END:VEVENT
BEGIN:VEVENT
UID:instant@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T020000Z
END:VEVENT
END:VCALENDAR
ICS);

    expect($calendar->eventsBetween(
        CarbonImmutable::parse('2026-08-03 02:00:00 UTC'),
        CarbonImmutable::parse('2026-08-03 03:00:00 UTC'),
    )->pluck('uid')->all())->toBe(['instant@example.test'])
        ->and(fn () => $calendar->eventsBetween(
            CarbonImmutable::parse('2026-08-03 03:00:00 UTC'),
            CarbonImmutable::parse('2026-08-03 03:00:00 UTC'),
        ))->toThrow(InvalidArgumentException::class);
});

it('expands recurring events with RDATE, EXDATE, overrides, and cancellations', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:series@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T090000Z
DTEND:20260803T100000Z
RRULE:FREQ=DAILY;COUNT=3
RDATE:20260810T090000Z
EXDATE:20260804T090000Z
SUMMARY:Master
END:VEVENT
BEGIN:VEVENT
UID:series@example.test
DTSTAMP:20260801T000000Z
RECURRENCE-ID:20260805T090000Z
DTSTART:20260805T110000Z
DTEND:20260805T120000Z
SUMMARY:Moved
END:VEVENT
BEGIN:VEVENT
UID:series@example.test
DTSTAMP:20260801T000000Z
RECURRENCE-ID:20260810T090000Z
DTSTART:20260810T090000Z
STATUS:CANCELLED
SUMMARY:Cancelled
END:VEVENT
END:VCALENDAR
ICS);

    $occurrences = $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 00:00:00 UTC'),
        CarbonImmutable::parse('2026-08-11 00:00:00 UTC'),
    );

    expect($occurrences->pluck('summary')->all())->toBe(['Master', 'Moved']);
    expect($occurrences->map(static fn (Event $event): ?string => $event->recurrenceId?->toIso8601String())->all())
        ->toBe(['2026-08-03T09:00:00+00:00', '2026-08-05T09:00:00+00:00'])
        ->and($calendar->eventsBetween(
            CarbonImmutable::parse('2026-08-03 00:00:00 UTC'),
            CarbonImmutable::parse('2026-08-11 00:00:00 UTC'),
        )->pluck('summary')->all())->toBe(['Master', 'Moved', 'Cancelled']);
});

it('rejects unsupported range overrides and invalid occurrence ranges', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:range@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T090000Z
RRULE:FREQ=DAILY;COUNT=2
END:VEVENT
BEGIN:VEVENT
UID:range@example.test
DTSTAMP:20260801T000000Z
RECURRENCE-ID;RANGE=THISANDFUTURE:20260804T090000Z
DTSTART:20260804T100000Z
END:VEVENT
END:VCALENDAR
ICS);

    expect(fn () => $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 UTC'),
        CarbonImmutable::parse('2026-08-05 UTC'),
    ))->toThrow(UnsupportedRecurrence::class)
        ->and(fn () => $calendar->occurrencesBetween(
            CarbonImmutable::parse('2026-08-05 UTC'),
            CarbonImmutable::parse('2026-08-05 UTC'),
        ))->toThrow(InvalidArgumentException::class);
});

it('keeps mutable date boundaries unchanged while accepting native and Carbon date-time values', function () {
    $calendar = ICalendar::read(calendarFixture('basic-event'));
    $from = new DateTime('2026-08-03 00:00:00', new DateTimeZone('UTC'));
    $until = Carbon::parse('2026-08-04 00:00:00 UTC');
    $fromBefore = clone $from;
    $untilBefore = $until->copy();

    $occurrences = $calendar->occurrencesBetween($from, $until);

    expect($occurrences)->toHaveCount(1)
        ->and($from)->toEqual($fromBefore)
        ->and($until)->toEqual($untilBefore);
});

it('rejects detached overrides', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:detached@example.test
DTSTAMP:20260801T000000Z
RECURRENCE-ID:20260803T090000Z
DTSTART:20260803T100000Z
SUMMARY:Detached
END:VEVENT
END:VCALENDAR
ICS);

    expect(fn () => $calendar->occurrencesBetween(
        CarbonImmutable::parse('2026-08-03 00:00:00 UTC'),
        CarbonImmutable::parse('2026-08-04 00:00:00 UTC'),
    ))->toThrow(UnsupportedRecurrence::class);
});

it('rejects multiple recurrence masters during document validation', function () {
    expect(fn () => ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:multiple@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T090000Z
RRULE:FREQ=DAILY;COUNT=2
END:VEVENT
BEGIN:VEVENT
UID:multiple@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T100000Z
RRULE:FREQ=DAILY;COUNT=2
END:VEVENT
END:VCALENDAR
ICS))->toThrow(InvalidCalendar::class);
});

it('supports absolute alarm triggers', function () {
    $event = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:absolute-alarm@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T020000Z
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER;VALUE=DATE-TIME:20260803T010000Z
DESCRIPTION:Reminder
END:VALARM
END:VEVENT
END:VCALENDAR
ICS)->events()->sole();
    $trigger = $event->alarms->sole()->trigger;

    expect($trigger?->isAbsolute())->toBeTrue()
        ->and($trigger?->isRelative())->toBeFalse()
        ->and($trigger?->dateTime()?->timezoneName)->toBe('UTC')
        ->and($trigger?->relatedTo())->toBeNull();
});

it('keeps a valid package timezone override when the application timezone is invalid', function () {
    config()->set('app.timezone', 'Not/A_Timezone');
    config()->set('icalendar_reader.floating_timezone', 'Europe/Paris');

    $calendar = ICalendar::read(calendarFixture('all-day-event'));

    expect($calendar->floatingTimezone)->toBe('Europe/Paris')
        ->and($calendar->warnings())->toHaveCount(1);
});

it('reports each invalid timezone setting and falls back to UTC', function () {
    config()->set('app.timezone', 'Not/App');
    config()->set('icalendar_reader.floating_timezone', 'Not/Package');

    $calendar = ICalendar::read(calendarFixture('all-day-event'));

    expect($calendar->floatingTimezone)->toBe('UTC')
        ->and($calendar->warnings())->toHaveCount(2)
        ->and($calendar->warnings()->pluck('message')->implode(' '))
        ->toContain('app.timezone')
        ->toContain('icalendar_reader.floating_timezone');
});

it('exposes a configuration warning while reading a floating-time fixture', function () {
    config()->set('app.timezone', 'Not/A_Timezone');

    $calendar = ICalendar::read(calendarFixture('floating-time-warning'));
    $warning = $calendar->warnings()->sole();

    expect($calendar->floatingTimezone)->toBe('UTC')
        ->and($calendar->events()->sole()->startIsFloating)->toBeTrue()
        ->and($warning->level)->toBe(2)
        ->and($warning->code)->toBe('invalid_timezone_configuration')
        ->and($warning->source)->toBe('configuration');
});

it('does not guess a timezone when TZID cannot be resolved', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:unknown-timezone@example.test
DTSTAMP:20260803T000000Z
DTSTART;TZID=Unknown/Zone:20260803T120000
END:VEVENT
END:VCALENDAR
ICS);
    $event = $calendar->events()->sole();
    $warning = $calendar->warnings()->sole();

    expect($event->startsAt)->toBeNull()
        ->and($event->property('DTSTART')?->value)->toBe('20260803T120000')
        ->and($warning->level)->toBe(2)
        ->and($warning->code)->toBe('mapping_warning')
        ->and($warning->source)->toBe('mapping')
        ->and($warning->component)->toBe('VEVENT')
        ->and($warning->property)->toBe('DTSTART');
});

it('maps Event DATE and recurrence flags with the same semantics as Todo', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:recurrence@example.test
DTSTAMP:20260803T000000Z
DTSTART;VALUE=DATE:20260803
DTEND;VALUE=DATE:20260804
SUMMARY:Master event
END:VEVENT
BEGIN:VEVENT
UID:recurrence@example.test
DTSTAMP:20260803T000000Z
DTSTART;VALUE=DATE:20260810
RECURRENCE-ID;VALUE=DATE:20260810
SUMMARY:Override event
END:VEVENT
END:VCALENDAR
ICS);

    $override = $calendar->events('recurrence@example.test')->last();
    $output = $calendar->toArray()['events'][1];

    expect($calendar->event('recurrence@example.test')?->summary)->toBe('Master event')
        ->and($override?->startIsDate)->toBeTrue()
        ->and($override?->endIsDate)->toBeTrue()
        ->and($override?->recurrenceId?->toDateString())->toBe('2026-08-10')
        ->and($override?->recurrenceIdIsDate)->toBeTrue()
        ->and($override?->recurrenceIdIsFloating)->toBeTrue()
        ->and($output)->toHaveKeys([
            'start_is_date', 'end_is_date', 'recurrence_id', 'recurrence_id_is_date',
            'recurrence_id_is_floating',
        ])
        ->and($output['starts_at'])->toBe('2026-08-10')
        ->and($output['recurrence_id'])->toBe('2026-08-10');
});

it('hydrates matching attendees and alarms for Event and Todo through the shared mapping path', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:event-relations@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T100000Z
ATTENDEE;CN=Taylor;ROLE=REQ-PARTICIPANT:mailto:taylor@example.test
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT15M
DESCRIPTION:Reminder
END:VALARM
END:VEVENT
BEGIN:VTODO
UID:todo-relations@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T100000Z
ATTENDEE;CN=Taylor;ROLE=REQ-PARTICIPANT:mailto:taylor@example.test
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT15M
DESCRIPTION:Reminder
END:VALARM
END:VTODO
END:VCALENDAR
ICS);

    $event = $calendar->events()->sole();
    $todo = $calendar->todos()->sole();

    expect($event->attendees->sole()->parameters())->toBe($todo->attendees->sole()->parameters())
        ->and($event->alarms->sole()->trigger?->duration()?->i)
        ->toBe($todo->alarms->sole()->trigger?->duration()?->i)
        ->and($event->alarms->sole()->action)->toBe($todo->alarms->sole()->action);
});

it('maps shared RFC core property shortcuts for Event and Todo from one normalized property list', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:event-core@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T100000Z
GEO:37.386013;-122.082932
TRANSP:transparent
COMMENT:First comment
COMMENT:Second comment
CONTACT:Reception
RESOURCES:Projector,Room A
RESOURCES:Whiteboard
RRULE:FREQ=DAILY;COUNT=2
ATTACH;FMTTYPE=text/plain:https://example.test/event.txt
EXDATE:20260804T100000Z
REQUEST-STATUS:2.0;Success
RELATED-TO;RELTYPE=CHILD:parent-event@example.test
RDATE:20260805T100000Z
END:VEVENT
BEGIN:VTODO
UID:todo-core@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T100000Z
GEO:37.386013;-122.082932
COMMENT:First comment
COMMENT:Second comment
CONTACT:Reception
RESOURCES:Projector,Room A
RESOURCES:Whiteboard
RRULE:FREQ=DAILY;COUNT=2
ATTACH;FMTTYPE=text/plain:https://example.test/todo.txt
EXDATE:20260804T100000Z
REQUEST-STATUS:2.0;Success
RELATED-TO;RELTYPE=CHILD:parent-todo@example.test
RDATE:20260805T100000Z
END:VTODO
END:VCALENDAR
ICS);

    $event = $calendar->events()->sole();
    $todo = $calendar->todos()->sole();
    $eventOutput = $calendar->toArray()['events'][0];
    $todoOutput = $calendar->toArray()['todos'][0];

    expect($event->geo)->toBe(['latitude' => 37.386013, 'longitude' => -122.082932])
        ->and($todo->geo)->toBe($event->geo)
        ->and($event->transparency)->toBe('TRANSPARENT')
        ->and($event->comments->all())->toBe(['First comment', 'Second comment'])
        ->and($todo->comments->all())->toBe($event->comments->all())
        ->and($event->contacts->all())->toBe(['Reception'])
        ->and($todo->contacts->all())->toBe($event->contacts->all())
        ->and($event->resources->all())->toBe(['Projector', 'Room A', 'Whiteboard'])
        ->and($todo->resources->all())->toBe($event->resources->all())
        ->and($event->recurrenceRule?->name)->toBe('RRULE')
        ->and($todo->recurrenceRule?->name)->toBe('RRULE')
        ->and($event->attachments->sole()->parameter('FMTTYPE'))->toBe('text/plain')
        ->and($todo->attachments->sole()->parameter('FMTTYPE'))->toBe('text/plain')
        ->and($event->exceptionDates->sole()->name)->toBe('EXDATE')
        ->and($todo->requestStatuses->sole()->name)->toBe('REQUEST-STATUS')
        ->and($event->relatedTo->sole()->parameter('RELTYPE'))->toBe('CHILD')
        ->and($todo->recurrenceDates->sole()->name)->toBe('RDATE')
        ->and($eventOutput)->toHaveKeys(['geo', 'transparency', 'comments', 'contacts', 'resources', 'recurrence_rule', 'attachments', 'exception_dates', 'request_statuses', 'related_to', 'recurrence_dates'])
        ->and($todoOutput)->toHaveKeys(['geo', 'comments', 'contacts', 'resources', 'recurrence_rule', 'attachments', 'exception_dates', 'request_statuses', 'related_to', 'recurrence_dates'])
        ->and($eventOutput['attachments'][0]['parameters']['FMTTYPE'])->toBe('text/plain')
        ->and($todoOutput['resources'])->toBe(['Projector', 'Room A', 'Whiteboard']);
});

it('preserves hydrated GEO precision in event todo and serialized shortcuts', function () {
    $calendar = ICalendar::read("BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//GEO Precision//EN\n"
        . "BEGIN:VEVENT\nUID:event-geo\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\nGEO:0.1234567890123456;-122.12345678901234\nEND:VEVENT\n"
        . "BEGIN:VTODO\nUID:todo-geo\nDTSTAMP:20260101T000000Z\nGEO:0.1234567890123456;-122.12345678901234\nEND:VTODO\nEND:VCALENDAR\n");
    $expected = ['latitude' => 0.1234567890123456, 'longitude' => -122.12345678901234];

    foreach ([$calendar->events()->sole(), $calendar->todos()->sole()] as $item) {
        expect($item->geo)->toBe($expected);
        expect($item->property('GEO')?->values)->toBe(\array_values($expected));
    }

    expect($calendar->toArray()['events'][0]['geo'])->toBe($expected);
    expect($calendar->toArray()['todos'][0]['geo'])->toBe($expected);
});

it('keeps an invalid GEO property generic without exposing an invalid typed coordinate pair', function () {
    $event = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VEVENT
UID:invalid-geo@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T100000Z
GEO:91;181
END:VEVENT
END:VCALENDAR
ICS)->events()->sole();

    expect($event->geo)->toBeNull()
        ->and($event->property('GEO')?->rawValue())->toBe('91;181');
});
