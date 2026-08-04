# Laravel iCalendar Reader Guide

[繁體中文](../zh-TW/guide/README.md)

English is the authoritative documentation for this package.

## Install and read

```bash
composer require mattmy/laravel-icalendar-reader
```

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($uploadedFile);
```

Each source has a matching `try*()` method. It returns `null` only when the
iCalendar content is invalid. Source, size, and configuration failures remain
exceptions.

## Calendar and events

`Calendar` exposes metadata, `events()`, `event($uid)`, `hasEvents()`,
`eventsBetween()`, `properties()`, `hasProperty()`, `property()`,
`components()`, and `warnings()`.

`Event` exposes `uid`, `summary`, `description`, `location`, `startsAt`,
`endsAt`, `lastDay`, `duration`, `timestamp`, `createdAt`, `lastModifiedAt`,
`status`, `classification`, `priority`, `sequence`, `url`, `organizer`,
`attendees`, `alarms`, and `categories`. Use `isAllDay()` instead of inferring
all-day status from midnight or duration.

Organizer and Attendee preserve their cal-address and parameters. Alarm keeps
its action, trigger, description, summary, attendees, repeat count, and
duration. An `AlarmTrigger` is either relative or absolute.

## Complete data access

`properties()` preserves direct properties in document order, including
duplicates, parameters, multiple values, recurrence data, and unknown `X-*`
properties. `components()` exposes untyped `VTODO`, `VJOURNAL`, `VFREEBUSY`,
`VTIMEZONE`, and unknown components without recursively flattening them.

Use `toArray()` or `toJson()` for the domain-oriented read model and
`toComponentArray()` for the complete normalized component tree. These methods
do not generate `.ics` and do not provide byte-for-byte round trips.

## Date and timezone semantics

- UTC and resolvable `TZID` values retain their timezone.
- Floating values use `icalendar_reader.floating_timezone`, then
  `app.timezone`, with UTC as the safe configuration fallback.
- An unresolved document `TZID` is not replaced by UTC. The typed value is
  `null`, the raw Property remains available, and Calendar receives a
  `mapping_warning`.
- All-day `DTEND` is exclusive; `lastDay` is the inclusive convenience date.
- `duration` is effective duration. `DTEND` wins when both `DTEND` and
  `DURATION` are present.
- `eventsBetween()` uses a half-open interval and does not expand recurrence.

## Validation and failures

Every input is parsed with strict Sabre options and then fully validated.
Level-two issues are returned from `warnings()`; level-three issues make the
document invalid.

`read*()` methods throw `InvalidCalendar`. Matching `try*()` methods convert
only that exception to `null`. Other stable exceptions are
`CalendarFileNotFound`, `CalendarFileUnreadable`, `CalendarTooLarge`,
`InvalidCalendarSource`, and `InvalidConfiguration`.

## Configuration and security

```php
return [
    'max_bytes' => 10 * 1024 * 1024,
    'floating_timezone' => null,
];
```

The reader never downloads URLs, does not trust upload MIME types, and does not
close caller-owned streams. Applications must still authorize local paths and
validate uploads. Avoid logging raw calendars because they can contain personal
information.

## Version policy

The package follows Semantic Versioning. Public API, exception, warning-code,
date interpretation, and array-shape changes are treated as compatibility
concerns. Version-specific changes are recorded in `CHANGELOG.md`.
