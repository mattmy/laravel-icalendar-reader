<?php

declare(strict_types=1);

use Mattmy\ICalendar\Facades\ICalendar;

it('selects a VTODO master and preserves every matching recurrence override', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VTODO
UID:task@example.test
DTSTAMP:20260803T000000Z
DTSTART;VALUE=DATE:20260803
DURATION:P2D
SUMMARY:Master task
END:VTODO
BEGIN:VTODO
UID:task@example.test
DTSTAMP:20260803T000000Z
RECURRENCE-ID;VALUE=DATE:20260810
SUMMARY:Override task
END:VTODO
END:VCALENDAR
ICS);

    $todo = $calendar->todo('task@example.test');

    expect($calendar->todos('task@example.test'))->toHaveCount(2)
        ->and($calendar->hasTodos('task@example.test'))->toBeTrue()
        ->and($todo?->summary)->toBe('Master task')
        ->and($todo?->startIsDate)->toBeTrue()
        ->and($todo?->startIsFloating)->toBeTrue()
        ->and($todo?->dueAt?->toDateString())->toBe('2026-08-05')
        ->and($todo?->dueIsDate)->toBeTrue()
        ->and($todo?->duration?->d)->toBe(2)
        ->and($calendar->todos('TASK@example.test'))->toBeEmpty();
});

it('includes typed todos in the calendar array without losing generic properties', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Tests//EN
BEGIN:VTODO
UID:duration@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260803T010000Z
DURATION:PT2H
PERCENT-COMPLETE:45
CATEGORIES:WORK,URGENT
X-TEAM:platform
END:VTODO
END:VCALENDAR
ICS);

    $todo = $calendar->todos()->sole();
    $output = $calendar->toArray()['todos'][0];

    expect($todo->dueAt?->toIso8601String())->toBe('2026-08-03T03:00:00+00:00')
        ->and($todo->property('X-TEAM')?->value)->toBe('platform')
        ->and($output)->toHaveKeys([
            'uid', 'timestamp', 'classification', 'completed_at', 'created_at', 'description',
            'starts_at', 'start_is_date', 'start_is_floating', 'due_at', 'due_is_date',
            'due_is_floating', 'duration', 'last_modified_at', 'location', 'organizer',
            'percent_complete', 'priority', 'recurrence_id', 'recurrence_id_is_date',
            'recurrence_id_is_floating', 'sequence', 'status', 'summary', 'url', 'attendees',
            'categories', 'alarms',
        ])
        ->and($output['due_at'])->toBe('2026-08-03T03:00:00+00:00')
        ->and($output['duration'])->toBe('PT2H')
        ->and($output['categories'])->toBe(['WORK', 'URGENT']);
});
