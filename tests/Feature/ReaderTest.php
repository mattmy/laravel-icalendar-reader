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
        ->and($event->uid)->toBe('architecture-review@example.test')
        ->and($event->summary)->toBe('Architecture review')
        ->and($event->startsAt)->toEqual(CarbonImmutable::parse('2026-08-03 06:30:00', 'UTC'))
        ->and($event->isAllDay())->toBeFalse();
});

it('identifies all-day events from the DTSTART value type', function () {
    $event = ICalendar::read(calendarFixture('all-day-event'))->events()->sole();

    expect($event->isAllDay())->toBeTrue()
        ->and($event->startsAt?->timezoneName)->toBe('Asia/Taipei')
        ->and($event->startsAt?->toDateString())->toBe('2026-08-03');
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
