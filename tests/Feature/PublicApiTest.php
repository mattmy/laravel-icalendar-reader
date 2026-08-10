<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Mattmy\ICalendar\Alarm;
use Mattmy\ICalendar\AlarmTrigger;
use Mattmy\ICalendar\Attendee;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\CalendarIssue;
use Mattmy\ICalendar\CalendarServiceProvider;
use Mattmy\ICalendar\Component;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Facades\ICalendar;
use Mattmy\ICalendar\Organizer;
use Mattmy\ICalendar\Reader;
use Mattmy\ICalendar\Todo;

it('resolves one shared reader through Laravel and the facade', function () {
    expect(app(Reader::class))->toBe(app(Reader::class))
        ->and(ICalendar::getFacadeRoot())->toBe(app(Reader::class));
});

it('merges defaults and registers the documented configuration publish mapping', function () {
    (new CalendarServiceProvider(app()))->boot();
    $paths = ServiceProvider::pathsToPublish(CalendarServiceProvider::class, 'icalendar-reader-config');

    expect(config('icalendar_reader.max_bytes'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('icalendar_reader.floating_timezone'))->toBeNull()
        ->and($paths)->toHaveCount(1)
        ->and(realpath((string) array_key_first($paths)))
        ->toBe(realpath(__DIR__ . '/../../config/icalendar_reader.php'))
        ->and(array_values($paths))->toBe([config_path('icalendar_reader.php')]);
});

it('supports the documented quick-start event loop', function () {
    $calendar = ICalendar::read(calendarFixture('basic-event'));
    $summaries = [];

    foreach ($calendar->events() as $event) {
        $summaries[] = $event->summary;
        expect($event->isAllDay())->toBe($event->allDay);
    }

    expect($summaries)->toBe(['Architecture review']);
});

it('keeps domain models final and readonly', function (string $class) {
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
})->with([
    Calendar::class,
    Event::class,
    Todo::class,
    Component::class,
    CalendarIssue::class,
    Organizer::class,
    Attendee::class,
    Alarm::class,
    AlarmTrigger::class,
]);

it('does not expose uncommitted standalone serializers on nested domain models', function (string $class) {
    expect(method_exists($class, 'toArray'))->toBeFalse()
        ->and(method_exists($class, 'jsonSerialize'))->toBeFalse();
})->with([
    Event::class,
    Todo::class,
    Component::class,
    Organizer::class,
    Attendee::class,
    Alarm::class,
    AlarmTrigger::class,
]);
