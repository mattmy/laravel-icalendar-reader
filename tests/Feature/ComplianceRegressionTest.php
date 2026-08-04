<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\CalendarIssue;
use Mattmy\ICalendar\Exceptions\CalendarTooLarge;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Exceptions\InvalidCalendarSource;
use Mattmy\ICalendar\Facades\ICalendar;
use Mattmy\ICalendar\Reader;
use Sabre\VObject\Component\VCalendar;

it('enforces the actual byte limit for every supported input source', function (string $source) {
    $contents = calendarFixture('basic-event');
    config()->set('icalendar_reader.max_bytes', \strlen($contents) - 1);
    $reader = app(Reader::class);
    $path = __DIR__ . '/../Fixtures/basic-event.ics';

    $call = match ($source) {
        'string' => fn () => $reader->read($contents),
        'path' => fn () => $reader->fromPath($path),
        'stream' => function () use ($reader, $path) {
            $stream = \fopen($path, 'rb');
            expect($stream)->not->toBeFalse();

            try {
                return $reader->fromStream($stream);
            } finally {
                if (\is_resource($stream)) {
                    \fclose($stream);
                }
            }
        },
        'upload' => fn () => $reader->fromUploadedFile(
            new UploadedFile($path, 'calendar.ics', 'text/calendar', null, true),
        ),
    };

    expect($call)->toThrow(CalendarTooLarge::class);
})->with(['string', 'path', 'stream', 'upload']);

it('keeps throwing and nullable APIs symmetric for invalid calendar contents', function (string $source) {
    $contents = 'not an iCalendar document';
    $path = \tempnam(\sys_get_temp_dir(), 'ics-invalid-');
    expect($path)->not->toBeFalse();
    \file_put_contents($path, $contents);
    $reader = app(Reader::class);

    try {
        [$throwing, $nullable] = match ($source) {
            'string' => [fn () => $reader->read($contents), fn () => $reader->tryRead($contents)],
            'path' => [fn () => $reader->fromPath($path), fn () => $reader->tryFromPath($path)],
            'stream' => [
                function () use ($reader, $path) {
                    $stream = \fopen($path, 'rb');

                    try {
                        return $reader->fromStream($stream);
                    } finally {
                        if (\is_resource($stream)) {
                            \fclose($stream);
                        }
                    }
                },
                function () use ($reader, $path) {
                    $stream = \fopen($path, 'rb');

                    try {
                        return $reader->tryFromStream($stream);
                    } finally {
                        if (\is_resource($stream)) {
                            \fclose($stream);
                        }
                    }
                },
            ],
            'upload' => [
                fn () => $reader->fromUploadedFile(new UploadedFile($path, 'invalid.ics', null, null, true)),
                fn () => $reader->tryFromUploadedFile(new UploadedFile($path, 'invalid.ics', null, null, true)),
            ],
        };

        expect($throwing)->toThrow(InvalidCalendar::class)
            ->and($nullable())->toBeNull();
    } finally {
        \unlink($path);
    }
})->with(['string', 'path', 'stream', 'upload']);

it('does not hide invalid uploaded file errors in nullable APIs', function () {
    $upload = new UploadedFile(__FILE__, 'calendar.ics', null, UPLOAD_ERR_NO_FILE, false);

    expect(fn () => app(Reader::class)->tryFromUploadedFile($upload))
        ->toThrow(InvalidCalendarSource::class);
});

it('maps event duration, recurrence properties, alarms, and defensive collections', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Compliance//EN
BEGIN:VEVENT
UID:duration@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000
DURATION:PT2H
RRULE:FREQ=DAILY;COUNT=3
RDATE:20260805T010000
EXDATE:20260804T010000
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT15M
DESCRIPTION:First
END:VALARM
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER;RELATED=END:-PT5M
DESCRIPTION:Second
END:VALARM
END:VEVENT
END:VCALENDAR
ICS);
    $event = $calendar->events()->sole();
    $events = $calendar->events();
    $events->pop();

    expect($event->endsAt?->toDateTimeString())->toBe('2026-08-03 03:00:00')
        ->and($event->endsAt?->timezoneName)->toBe('Asia/Taipei')
        ->and($event->startIsFloating)->toBeTrue()
        ->and($event->endIsFloating)->toBeTrue()
        ->and($event->duration?->h)->toBe(2)
        ->and($event->alarms)->toHaveCount(2)
        ->and($event->alarms->last()?->trigger?->relatedTo())->toBe('END')
        ->and($event->hasProperty('RRULE'))->toBeTrue()
        ->and($event->hasProperty('RDATE'))->toBeTrue()
        ->and($event->hasProperty('EXDATE'))->toBeTrue()
        ->and($calendar->events())->toHaveCount(1);
});

it('derives stable durations and all-day last-day semantics', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Duration//EN
BEGIN:VEVENT
UID:timed@example.test
DTSTAMP:20260801T000000Z
DTSTART:20260803T010000Z
DTEND:20260805T013000Z
END:VEVENT
BEGIN:VEVENT
UID:all-day@example.test
DTSTAMP:20260801T000000Z
DTSTART;VALUE=DATE:20260803
DTEND;VALUE=DATE:20260806
END:VEVENT
END:VCALENDAR
ICS);
    $timed = $calendar->event('timed@example.test');
    $allDay = $calendar->event('all-day@example.test');

    expect($timed?->duration?->days)->toBe(2)
        ->and($timed?->duration?->h)->toBe(0)
        ->and($timed?->duration?->i)->toBe(30)
        ->and($timed?->lastDay)->toBeNull()
        ->and($allDay?->endsAt?->toDateString())->toBe('2026-08-06')
        ->and($allDay?->lastDay?->toDateString())->toBe('2026-08-05');
});

it('isolates raw child component clones from hydrated and subsequent raw data', function () {
    $calendar = ICalendar::read(calendarFixture('basic-event'));
    $event = $calendar->events()->sole();
    $rawEvent = $event->rawComponent();
    $rawEvent->SUMMARY = 'Changed';

    expect($event->summary)->toBe('Architecture review')
        ->and((string) $event->rawComponent()->SUMMARY)->toBe('Architecture review');
});

it('keeps calendar and issue serialization contracts stable', function () {
    $calendar = ICalendar::read(calendarFixture('basic-event'));
    $issue = new CalendarIssue(2, 'mapping_warning', 'Example', 'mapping', 12, 'VEVENT', 'DTSTART');

    expect($calendar->jsonSerialize())->toBe($calendar->toArray())
        ->and(\json_decode($calendar->toJson(JSON_PRETTY_PRINT), true, flags: JSON_THROW_ON_ERROR))
        ->toBe($calendar->toArray())
        ->and($issue->jsonSerialize())->toBe($issue->toArray())
        ->and(\json_encode($issue, JSON_THROW_ON_ERROR))->toBe(\json_encode($issue->toArray(), JSON_THROW_ON_ERROR));

    $invalidUtf8 = new Calendar(
        version: "\xB1\x31",
        productId: null,
        method: null,
        calendarScale: null,
        floatingTimezone: 'UTC',
        eventItems: [],
        warningItems: [],
        propertyItems: [],
        componentItems: [],
        component: new VCalendar(),
    );

    expect(fn () => $invalidUtf8->toJson())->toThrow(JsonException::class);
});

it('handles half-open all-day ranges', function () {
    $calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Ranges//EN
BEGIN:VEVENT
UID:all-day@example.test
DTSTAMP:20260801T000000Z
DTSTART;VALUE=DATE:20260803
END:VEVENT
END:VCALENDAR
ICS);

    expect($calendar->eventsBetween(
        CarbonImmutable::parse('2026-08-03 00:00:00 Asia/Taipei'),
        CarbonImmutable::parse('2026-08-04 00:00:00 Asia/Taipei'),
    )->pluck('uid')->all())->toBe(['all-day@example.test']);
});

it('rejects events without DTSTART before creating an event model', function () {
    $contents = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Missing Start//EN
BEGIN:VEVENT
UID:no-start@example.test
DTSTAMP:20260801T000000Z
END:VEVENT
END:VCALENDAR
ICS;

    expect(fn () => ICalendar::read($contents))->toThrow(InvalidCalendar::class)
        ->and(ICalendar::tryRead($contents))->toBeNull();
});
