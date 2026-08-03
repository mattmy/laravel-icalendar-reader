<?php

declare(strict_types=1);

use Mattmy\ICalendar\Alarm;
use Mattmy\ICalendar\AlarmTrigger;
use Mattmy\ICalendar\Attendee;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\CalendarIssue;
use Mattmy\ICalendar\Component;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Facades\ICalendar;
use Mattmy\ICalendar\Organizer;
use Mattmy\ICalendar\Property;
use Mattmy\ICalendar\Reader;

it('resolves one shared reader through Laravel and the facade', function () {
    expect(app(Reader::class))->toBe(app(Reader::class))
        ->and(ICalendar::getFacadeRoot())->toBe(app(Reader::class));
});

it('keeps domain models final and readonly', function (string $class) {
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
})->with([
    Calendar::class,
    Event::class,
    Component::class,
    Property::class,
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
    Component::class,
    Property::class,
    Organizer::class,
    Attendee::class,
    Alarm::class,
    AlarmTrigger::class,
]);
