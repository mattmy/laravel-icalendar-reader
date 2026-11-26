# Laravel iCalendar Reader

[繁體中文](README.zh-TW.md)

Read and validate `.ics` files in Laravel with the `ICalendar` facade.

## Features

- Query events, todos, and journals by UID. Use date ranges to find events and expand recurring events.
- Read dates as `CarbonImmutable` and durations as `DateInterval`. Access organizers, attendees,
  delegation details, and alarms through typed fields.
- Use `Property` and `Component` for repeated values, extensions, and components such as `VFREEBUSY`.
- Export common fields as an array or JSON, or get the complete property and component tree.

## Requirements

| Requirement | Declared support | Continuously tested |
| --- | --- | --- |
| PHP | 8.2 or later in the PHP 8.x series | 8.2, 8.3, 8.4, 8.5 |
| Laravel | 11, 12, 13 | 11, 12, 13 |
| PHP extensions | DOM, JSON, Multibyte String, XMLReader, XMLWriter | Checked by Composer during installation |
| libxml | 2.6.20 or later | Checked by Composer during installation |

The PHP extensions and libxml requirement come through Sabre/VObject 5 and Sabre/XML.

## Installation

```bash
composer require mattmy/laravel-icalendar-reader
```

## Configuration

- `max_bytes` defaults to 10 MiB per input.
- `floating_timezone` defaults to `null`. DATE values and date-times without `Z` or `TZID` use
  `app.timezone` when it is valid, or UTC otherwise.

Publish `config/icalendar_reader.php` to change these settings.

```bash
php artisan vendor:publish --tag=icalendar-reader-config
```

## Quick start

```php
use Mattmy\ICalendar\Facades\ICalendar;

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

Choose one method for your input source.

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read($contents);
$calendar = ICalendar::fromPath($path);
$calendar = ICalendar::fromStream($stream);
$calendar = ICalendar::fromUploadedFile($uploadedFile);
```

All four methods return a `Calendar` on success. Use the matching `try*()` method, such as
`tryFromPath()`, to get `null` for invalid content. Source, size, and configuration failures
still throw their own exceptions.

```php
$events = $calendar->events('event@example.test');
$event = $calendar->event('event@example.test');
$todos = $calendar->todos();
$journals = $calendar->journals();
$journal = $calendar->journal('entry@example.test');
$eventsInRange = $calendar->eventsBetween($from, $until);
$occurrences = $calendar->occurrencesBetween($from, $until);
$freeBusy = $calendar->component('VFREEBUSY');
$customProperty = $calendar->property('X-CUSTOM');
$json = $calendar->toJson(JSON_PRETTY_PRINT);
```

UID matching is case-sensitive. `events()`, `todos()`, and `journals()` return results in
calendar order. `eventsBetween()` checks the events stored in the calendar.
`occurrencesBetween()` also expands recurring events, including `RDATE;VALUE=PERIOD`,
and sorts results by start time. It throws `RecurrenceLimitExceeded` if it needs to check
more than 3,500 candidates.

`eventsBetween()` throws `UnresolvableEventRange` when it cannot resolve a required end time.
The same applies to non-recurring events in `occurrencesBetween()`. If recurrence expansion
needs a date it cannot resolve, it throws `UnsupportedRecurrence`.

`TZID` dates use the calendar’s matching `VTIMEZONE`.

## Validation and warnings

```php
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Facades\ICalendar;

try {
    $calendar = ICalendar::read($contents);
    $warnings = $calendar->warnings();
} catch (InvalidCalendar $exception) {
    $issues = $exception->issues();
}
```

Each read accepts exactly one `VCALENDAR`. Use `InvalidCalendar::issues()` to inspect rejected
content and `Calendar::warnings()` to check for warnings after a successful read. A valid
rule can be read even when `occurrencesBetween()` would throw `UnsupportedRecurrence`.

## Performance and security

The reader loads the full calendar into memory. Set `max_bytes` to suit your application.
Pass trusted local paths to `fromPath()` and close streams you open. Escape calendar text
before displaying it, validate links before opening them, and avoid logging full calendars
that may contain personal data.

## Documentation

- [Complete documentation](https://mattmy.github.io/laravel-icalendar-reader-doc/)
- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)

## License

The MIT License. See [LICENSE](LICENSE).
