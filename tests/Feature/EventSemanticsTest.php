<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Mattmy\ICalendar\Facades\ICalendar;

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
