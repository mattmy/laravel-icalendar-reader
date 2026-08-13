# Laravel iCalendar Reader

[繁體中文](README.zh-TW.md)

Read and validate `.ics` calendars in Laravel, then query events, todos, dates, participants,
alarms, recurrence data, properties, and components through `ICalendar`.

## Features

- Read complete strings, local files, streams, and Laravel uploaded files with a configurable
  byte limit.
- Query events and todos in document order, by exact UID, or by date range.
- Get common calendar fields as `CarbonImmutable`, `DateInterval`, and Laravel Collections.
- Inspect organizers, attendees, delegation details, alarms, recurrence data, and all-day or
  floating-time behavior.
- Access repeated, custom, and non-event data through `Property` and `Component` objects.
- Choose exceptions or `null` for invalid content and inspect structured warnings after a
  successful read.
- Convert results to arrays, JSON, or a complete property and component tree.

## Requirements

| Requirement | Declared support | Continuously tested |
| --- | --- | --- |
| PHP | 8.3 or later in the PHP 8.x series | 8.3, 8.4, 8.5 |
| Laravel | 11, 12, 13 | 11, 12, 13 |
| PHP extensions | DOM, JSON, Multibyte String, XMLReader, XMLWriter | Checked by Composer during installation |
| libxml | 2.6.20 or later | Checked by Composer during installation |

The PHP extensions and libxml requirement come through Sabre/VObject 5 and Sabre/XML.

## Installation

```bash
composer require mattmy/laravel-icalendar-reader
```

## Configuration

The package works immediately with these defaults:

- `max_bytes`: accepts up to 10 MiB per input.
- `floating_timezone`: uses `app.timezone` for date-times without `Z` or `TZID`.

Publish `config/icalendar_reader.php` when you need different values:

```bash
php artisan vendor:publish --tag=icalendar-reader-config
```

## Quick start

```php
$calendar = ICalendar::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example//Calendar//EN
BEGIN:VEVENT
UID:meeting@example.test
DTSTAMP:20260803T000000Z
DTSTART:20260810T090000Z
SUMMARY:Project meeting
END:VEVENT
END:VCALENDAR
ICS);

$event = $calendar->events()->sole();

echo $event->summary; // Project meeting
echo $event->startsAt?->toIso8601String(); // 2026-08-10T09:00:00+00:00
```

## Read calendar data

Choose the method that matches the input source:

```php
$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($uploadedFile);
```

Each method returns the same `Calendar` type. Its matching `try*()` method returns `null` for
invalid iCalendar content; source, size, and configuration failures keep their specific
exceptions.

```php
$events = $calendar->events('event@example.test');
$event = $calendar->event('event@example.test');
$todos = $calendar->todos();
$eventsInRange = $calendar->eventsBetween($from, $until);
$occurrences = $calendar->occurrencesBetween($from, $until);
$freeBusy = $calendar->component('VFREEBUSY');
$customProperty = $calendar->property('X-CUSTOM');
$json = $calendar->toJson(JSON_PRETTY_PRINT);
```

UID matching is case-sensitive. Collections preserve document order. `eventsBetween()` uses
the event components present in the input; `occurrencesBetween()` expands bounded VEVENT
recurrence, including explicit `RDATE;VALUE=PERIOD` durations, with a 3,500-candidate work cap.

Matching calendar `VTIMEZONE` definitions take precedence over host tzdata. Alarm objects
expose attachments, direct properties, extension data, and defensive raw component clones.

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

`issues()` explains rejected content. `warnings()` reports readable content or configuration
that needs attention. Validation includes package-level temporal, alarm, date-time, integer,
and PERIOD rules in addition to Sabre validation; this API accepts exactly one VCALENDAR object.

## Performance and security

Keep `max_bytes` appropriate for the largest calendar your application accepts. Use trusted
local paths with `fromPath()`, close caller-owned streams, escape calendar text before display,
validate calendar URLs before navigation, and avoid logging complete input or output that may
contain personal data.

## Documentation

- [Complete documentation](https://mattmy.github.io/laravel-icalendar-reader-doc/)
- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)

## License

The MIT License. See [LICENSE](LICENSE).
