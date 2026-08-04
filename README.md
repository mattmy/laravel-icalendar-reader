# Laravel iCalendar Reader

[繁體中文](README.zh-TW.md)

Read `.ics` calendars with a Laravel-friendly API. Get events, dates, all-day status,
organizers, attendees, alarms, properties, and components without working with raw
iCalendar text.

## What you can do

- Read `.ics` content from a string, local file, stream, or Laravel uploaded file.
- Get all events or find events by UID and date range.
- Read event dates, duration, location, status, categories, and other common fields.
- Check whether an event is all day with `$event->allDay` or `$event->isAllDay()`.
- Get organizers, attendees, delegation details, alarms, and alarm triggers.
- Read `VTODO`, `VJOURNAL`, `VFREEBUSY`, `VTIMEZONE`, custom properties, and unknown components.
- Choose whether invalid calendar content throws an exception or returns `null`.
- Get readable warnings for calendar content that needs attention.
- Convert calendar data to an array, JSON, or a complete component tree.

This package reads calendars only. It does not generate `.ics` files or expand recurring
events into individual occurrences.

## Installation

```bash
composer require mattmy/laravel-icalendar-reader
```

## Quick start

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::fromUploadedFile($request->file('calendar'));

foreach ($calendar->events() as $event) {
    echo $event->summary;
    echo $event->startsAt?->toDateTimeString();
    echo $event->endsAt?->toDateTimeString();

    if ($event->allDay) {
        echo 'All day';
    }
}
```

## Read `.ics` content

```php
$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($request->file('calendar'));
```

Use the matching `try*()` method when invalid calendar content should return `null`:

```php
$calendar = ICalendar::tryRead($contents);
$calendar = ICalendar::tryFromPath($path);
$calendar = ICalendar::tryFromStream($stream);
$calendar = ICalendar::tryFromUploadedFile($request->file('calendar'));
```

File, stream, upload, size, and configuration errors still throw their matching exceptions.

## Work with events

```php
$events = $calendar->events();
$eventsWithUid = $calendar->events('event@example.com');
$event = $calendar->event('event@example.com');
$hasEvents = $calendar->hasEvents();
$hasEvent = $calendar->hasEvents('event@example.com');
$eventsInRange = $calendar->eventsBetween($from, $until);
```

An `Event` gives you:

- `uid`, `summary`, `description`, `location`, and `url`
- `startsAt`, `endsAt`, `lastDay`, and `duration`
- `allDay`, `startIsFloating`, and `endIsFloating`
- `status`, `classification`, `priority`, and `sequence`
- `timestamp`, `createdAt`, and `lastModifiedAt`
- `organizer`, `attendees`, `alarms`, and `categories`

Dates are returned as `CarbonImmutable`, durations as `DateInterval`, and lists as Laravel
Collections. `eventsBetween()` returns events found in the `.ics` file; recurring rules are
not expanded into extra occurrences.

## Organizers, attendees, and alarms

```php
$organizer = $event->organizer;
$organizer?->email;
$organizer?->name;

foreach ($event->attendees as $attendee) {
    $attendee->email;
    $attendee->name;
    $attendee->role;
    $attendee->status;
    $attendee->rsvp;
}

foreach ($event->alarms as $alarm) {
    $alarm->action;
    $alarm->description;
    $alarm->trigger?->duration();
    $alarm->trigger?->dateTime();
}
```

## Properties and components

Use properties and components when the data you need does not have a dedicated Event field.

```php
$summaryProperty = $event->property('SUMMARY');
$rules = $event->properties('RRULE');
$hasLocation = $event->hasProperty('LOCATION');

$freeBusy = $calendar->component('VFREEBUSY');
$todos = $calendar->components('VTODO');
$hasTimezones = $calendar->hasComponent('VTIMEZONE');

$periods = $freeBusy?->properties('FREEBUSY');
$busyType = $periods?->first()?->parameter('FBTYPE');
```

Property and component names are not case-sensitive. `property()` and `component()` return
the first match; `properties()` and `components()` return every match.

## Validation and warnings

```php
use Mattmy\ICalendar\Exceptions\InvalidCalendar;

try {
    $calendar = ICalendar::read($contents);
} catch (InvalidCalendar $exception) {
    $issues = $exception->issues();
}

$warnings = $calendar->warnings();
```

Use `issues()` when invalid content is rejected. Use `warnings()` after a successful read to
find content or configuration that may need attention.

## Arrays and JSON

```php
$data = $calendar->toArray();
$json = $calendar->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$tree = $calendar->toComponentArray();
```

`toArray()` and `toJson()` contain common calendar and event data. `toComponentArray()`
contains the complete property and component tree, including non-event and custom data.

## Limits and safety

Input size is limited by `icalendar_reader.max_bytes`. Calendar fields may contain personal
or user-provided text and URLs, so escape displayed text, check links before using them, and
avoid logging complete calendar contents.

For every method, field, parameter, exception, timezone note, and performance consideration,
see the [documentation](https://mattmy.github.io/laravel-icalendar-reader-doc/).

## License

The MIT License. See [LICENSE](LICENSE).
