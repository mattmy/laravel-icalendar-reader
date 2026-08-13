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
`components()`, `hasComponent()`, `component()`, and `warnings()`.

Pass a UID to `events($uid)` to return every exact, case-sensitive UID match in
document order, including recurrence overrides. Passing `null` returns all
events; no match returns an empty Collection. `hasEvents($uid)` uses the same
rules, while `event($uid)` returns one event using the documented recurrence
selection rules.

`Event` exposes `uid`, `summary`, `description`, `location`, `startsAt`,
`endsAt`, `lastDay`, `duration`, `timestamp`, `createdAt`, `lastModifiedAt`,
`status`, `classification`, `priority`, `sequence`, `url`, `organizer`,
`attendees`, `alarms`, and `categories`. The readonly `allDay` property and
`isAllDay()` return the same value; use either instead of inferring all-day
status from midnight or duration.

Organizer and Attendee preserve their cal-address and parameters. Alarm keeps
its action, trigger, description, summary, attendees, repeat count, and
duration. It also exposes `attachments`, direct property queries, extension
properties, and a defensive `rawComponent()` clone. An `AlarmTrigger` is
either relative or absolute.

## Complete data access

`properties()` preserves direct properties in document order, including
duplicates, parameters, multiple values, recurrence data, and unknown `X-*`
properties. `components()` exposes untyped `VTODO`, `VJOURNAL`, `VFREEBUSY`,
`VTIMEZONE`, and unknown components without recursively flattening them.
Use `component($name)` for the first direct match and `hasComponent($name)` for
a presence check; both compare names case-insensitively and never recurse.

Use `toArray()` or `toJson()` for the domain-oriented read model and
`toComponentArray()` for the complete normalized component tree. These methods
do not generate `.ics` and do not provide byte-for-byte round trips.

## Date and timezone semantics

- UTC and resolvable `TZID` values retain their timezone. A matching
  `VTIMEZONE` definition is authoritative even when the host recognizes the
  same identifier.
- Floating values use `icalendar_reader.floating_timezone` when it is a valid IANA
  timezone; when it is unset, they use a valid `app.timezone`, otherwise UTC. An
  invalid package override falls back directly to UTC, even when `app.timezone` is valid.
- An unresolved document `TZID` is not replaced by UTC. The typed value is
  `null`, the raw Property remains available, and Calendar receives a
  `mapping_warning`.
- All-day `DTEND` is exclusive; `lastDay` is the inclusive convenience date.
- `duration` is effective duration. Invalid `DTEND + DURATION` and
  `DUE + DURATION` combinations are rejected.
- `eventsBetween()` uses a half-open interval and does not expand recurrence.
- `occurrencesBetween()` expands a bounded VEVENT recurrence query, including
  `RDATE;VALUE=PERIOD` explicit durations, and enforces a 3,500-candidate work
  limit before exclusions and de-duplication.

## Validation and failures

Every input is parsed with strict Sabre options and then validated by Sabre
and package-level RFC semantic checks. The single-calendar API rejects a
stream containing zero or multiple VCALENDAR objects.
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
