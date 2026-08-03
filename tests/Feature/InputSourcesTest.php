<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Mattmy\ICalendar\Exceptions\CalendarFileNotFound;
use Mattmy\ICalendar\Exceptions\CalendarTooLarge;
use Mattmy\ICalendar\Exceptions\InvalidCalendarSource;
use Mattmy\ICalendar\Reader;

it('reads local paths, streams, and uploaded files through the same parser', function () {
    $path = __DIR__ . '/../Fixtures/basic-event.ics';
    $stream = \fopen($path, 'rb');

    expect($stream)->not->toBeFalse();

    try {
        $reader = app(Reader::class);
        $upload = new UploadedFile($path, 'calendar.ics', 'text/calendar', null, true);

        expect($reader->fromPath($path)->events()->sole()->uid)
            ->toBe('architecture-review@example.test')
            ->and($reader->fromStream($stream)->events()->sole()->uid)
            ->toBe('architecture-review@example.test')
            ->and($reader->fromUploadedFile($upload)->events()->sole()->uid)
            ->toBe('architecture-review@example.test')
            ->and(\is_resource($stream))->toBeTrue();
    } finally {
        if (\is_resource($stream)) {
            \fclose($stream);
        }
    }
});

it('reads streams from their current position without closing them', function () {
    $stream = \fopen(__DIR__ . '/../Fixtures/basic-event.ics', 'rb');

    expect($stream)->not->toBeFalse();

    try {
        $prefix = \fread($stream, 6);

        expect($prefix)->toBe('BEGIN:');

        $calendar = app(Reader::class)->tryFromStream($stream);

        expect($calendar)->toBeNull()
            ->and(\is_resource($stream))->toBeTrue();
    } finally {
        if (\is_resource($stream)) {
            \fclose($stream);
        }
    }
});

it('keeps source and size failures visible through nullable APIs', function () {
    $reader = app(Reader::class);

    expect(fn () => $reader->tryFromPath(__DIR__ . '/missing.ics'))
        ->toThrow(CalendarFileNotFound::class)
        ->and(fn () => $reader->tryFromStream('not a stream'))
        ->toThrow(InvalidCalendarSource::class);

    config()->set('icalendar_reader.max_bytes', 10);

    expect(fn () => $reader->tryFromPath(__DIR__ . '/../Fixtures/basic-event.ics'))
        ->toThrow(CalendarTooLarge::class);
});

it('stops reading a stream as soon as the byte limit is exceeded', function () {
    $stream = \fopen('php://temp', 'w+b');

    expect($stream)->not->toBeFalse();

    try {
        \fwrite($stream, '1234567890');
        \rewind($stream);
        config()->set('icalendar_reader.max_bytes', 5);

        expect(fn () => app(Reader::class)->fromStream($stream))
            ->toThrow(CalendarTooLarge::class)
            ->and(\ftell($stream))->toBe(6)
            ->and(\is_resource($stream))->toBeTrue();
    } finally {
        if (\is_resource($stream)) {
            \fclose($stream);
        }
    }
});
