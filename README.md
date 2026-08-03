# Laravel iCalendar Reader

[繁體中文](README.zh-TW.md)

Read, validate, and query `.ics` calendars through a clean, typed Laravel API. Sabre/VObject handles RFC parsing while this package provides immutable Laravel-friendly objects, Collections, structured errors, and safe input boundaries.

> The package is under active 0.x development. The public API may change before 1.0.

## Installation

```bash
composer require mattmy/laravel-icalendar-reader
```

Laravel discovers the service provider and `ICalendar` facade automatically. To publish the optional configuration:

```bash
php artisan vendor:publish --tag=icalendar-reader-config
```

## Quick start

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read($contents);

foreach ($calendar->events() as $event) {
    $event->summary;
    $event->startsAt;
    $event->endsAt;
    $event->isAllDay();
    $event->organizer;
    $event->attendees;
    $event->alarms;
}
```

Read from an iCalendar string, local path, caller-owned stream, or Laravel `UploadedFile`:

```php
$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($request->file('calendar'));
```

Every source uses the same strict parse and validation pipeline. Throwing methods raise `InvalidCalendar` for invalid content; matching `try*()` methods return `null` only for invalid content. File, stream, upload, size, and configuration failures remain exceptions.

## Query and preserve data

```php
$event = $calendar->event($uid);
$events = $calendar->eventsBetween($from, $until);
$freeBusy = $calendar->components('VFREEBUSY')->first();
$periods = $freeBusy?->properties('FREEBUSY');
$fbType = $periods?->first()?->parameter('FBTYPE');

$calendar->toArray();          // Domain-oriented output
$calendar->toJson();           // JSON_THROW_ON_ERROR
$calendar->toComponentArray(); // Complete normalized component tree
```

Repeated properties, parameters, multi-values, recurrence properties, `VTODO`, `VJOURNAL`, `VFREEBUSY`, `VTIMEZONE`, vendor properties, and unknown components remain accessible through `Property` and generic `Component` objects.

## Time semantics

- UTC and `TZID` date-times retain their timezone.
- Floating values use `icalendar_reader.floating_timezone`, then `app.timezone`.
- Invalid timezone configuration falls back to UTC and creates a Calendar warning.
- `isAllDay()` checks the `DTSTART` value type instead of guessing from midnight or duration.
- All-day `DTEND` remains exclusive; `lastDay` provides the inclusive convenience date.

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

## Limits and security

The package does not generate calendars, download remote URLs, implement CalDAV, persist data, or expand recurrence occurrences. `eventsBetween()` only queries concrete `VEVENT` components present in the document.

All inputs are limited by `icalendar_reader.max_bytes` before parsing. `fromPath()` accepts readable local regular files only, streams remain owned by the caller, and errors do not include complete calendar contents. Calendar data can contain personal information; avoid logging raw input or full parsed output by default.

## License

The MIT License. See [LICENSE](LICENSE).
