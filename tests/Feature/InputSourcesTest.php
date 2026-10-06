<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Mattmy\ICalendar\Exceptions\CalendarFileNotFound;
use Mattmy\ICalendar\Exceptions\CalendarTooLarge;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Exceptions\InvalidCalendarSource;
use Mattmy\ICalendar\Reader;
use Mockery\CompositeExpectation;

it('applies original scalar and recurrence rejection to every input source', function (string $property) {
    $contents = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Example//Input Validation//EN\nBEGIN:VEVENT\n"
        . "UID:source\nDTSTAMP:20260101T000000Z\nDTSTART:20260101T090000Z\n{$property}\nEND:VEVENT\nEND:VCALENDAR\n";
    $path = \tempnam(\sys_get_temp_dir(), 'icalendar-original-');
    if ($path === false) {
        throw new RuntimeException('Unable to create a source fixture.');
    }
    $stream = null;

    try {
        if (\file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write a source fixture.');
        }
        $stream = \fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the source fixture.');
        }
        $reader = app(Reader::class);
        $upload = new UploadedFile($path, 'calendar.ics', 'text/calendar', null, true);
        expect(fn () => $reader->fromPath($path))->toThrow(InvalidCalendar::class)
            ->and($reader->tryFromPath($path))->toBeNull();
        expect(fn () => $reader->fromUploadedFile($upload))->toThrow(InvalidCalendar::class)
            ->and($reader->tryFromUploadedFile($upload))->toBeNull();
        expect(fn () => $reader->fromStream($stream))->toThrow(InvalidCalendar::class);
        \rewind($stream);
        expect($reader->tryFromStream($stream))->toBeNull()
            ->and(\is_resource($stream))->toBeTrue();
    } finally {
        if (\is_resource($stream)) {
            \fclose($stream);
        }
        \unlink($path);
    }
})->with([
    'boolean' => 'X-SCALAR;VALUE=BOOLEAN:maybe', 'float' => 'X-SCALAR;VALUE=FLOAT:1e2',
    'rule' => 'RRULE:FREQ=DAILY;COUNT=1;COUNT=2',
]);

it('reads local paths, streams, and uploaded files through the same parser', function () {
    $path = __DIR__ . '/../Fixtures/basic-event.ics';
    $stream = \fopen($path, 'rb');

    if ($stream === false) {
        throw new RuntimeException('Unable to open the test stream.');
    }

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

    if ($stream === false) {
        throw new RuntimeException('Unable to open the test stream.');
    }

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

it('distinguishes a vanished uploaded backing file from an unreadable file', function () {
    $file = Mockery::mock(UploadedFile::class)->makePartial();
    $configure = static function (mixed $expectation, mixed $result): void {
        if (! $expectation instanceof CompositeExpectation) {
            throw new LogicException('Expected a composite upload method expectation.');
        }

        $expectation->__call('once', [])->andReturn($result);
    };
    $configure($file->shouldReceive('isValid'), true);
    $configure($file->shouldReceive('getRealPath'), false);
    $configure($file->shouldReceive('getPathname'), __DIR__ . '/../Fixtures/missing-upload.ics');

    if (! $file instanceof UploadedFile) {
        throw new LogicException('The upload mock must extend UploadedFile.');
    }

    expect(fn () => app(Reader::class)->fromUploadedFile($file))
        ->toThrow(CalendarFileNotFound::class);
});

it('stops reading a stream as soon as the byte limit is exceeded', function () {
    $stream = \fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Unable to open the test stream.');
    }

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
