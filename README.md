# Laravel iCalendar Reader

[繁體中文](README.zh-TW.md)

A clean, typed iCalendar reader for Laravel, powered by Sabre/VObject.

> This package is under active development and its public API is not stable yet.

## Current development slice

The package currently provides strict parsing and validation for strings, local paths,
streams, and Laravel uploaded files. It exposes the first typed Calendar and Event
fields, including a reliable `isAllDay()` method based on the `DTSTART` value type.

```php
use Mattmy\ICalendar\Facades\ICalendar;

$calendar = ICalendar::read($contents);
$event = $calendar->events()->first();

if ($event?->isAllDay()) {
    // DTSTART is an iCalendar DATE value.
}
```

Invalid content throws `InvalidCalendar` through `read*()` methods. Matching `try*()`
methods return `null` only for invalid iCalendar content; source, size, and configuration
errors remain visible.

Generation, remote URL downloads, CalDAV, and recurrence expansion are intentionally
outside the 1.0 scope.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
