# Changelog

All notable changes to this project are documented here. The format follows Keep a Changelog and the project follows Semantic Versioning.

## [Unreleased]

### Added

- RFC semantic validation for temporal relationships, action-specific VALARM grammar, date-time forms, INTEGER ranges, and PERIOD values.
- VEVENT `RDATE;VALUE=PERIOD` expansion with explicit per-occurrence duration.
- Alarm attachment, direct property, extension property, and defensive raw-component access.

### Fixed

- Bounded and work-limited recurrence expansion, including infinite rules and EXDATE-filtered candidates.
- Calendar-defined VTIMEZONE observances now take precedence over same-named host timezones.
- Multiple VCALENDAR objects are rejected instead of silently truncating input.
- Nested extension component snapshots no longer clone every descendant subtree eagerly.

## [0.2.0] - 2026-08-13

### Added

- `Calendar::occurrencesBetween()` for querying concrete event occurrences in a half-open date range.
- Recurrence expansion for `RRULE` and `RDATE`, with `EXDATE`, overrides, and cancellations applied.
- Carbon and native `DateTimeInterface` boundary support without mutating the supplied date-time objects.
- `UnsupportedRecurrence` and `RecurrenceLimitExceeded` exceptions for unsafe recurrence forms and the 3,500-candidate query limit.
- A recurring-event fixture covering inclusion, exclusion, overrides, cancellations, one-time events, and all-day events.

## [0.1.0] - 2026-08-11

### Added

- Strict iCalendar parsing, full Sabre/VObject validation, and structured issues.
- Typed Calendar, Event, Todo, Organizer, Attendee, Alarm, and AlarmTrigger APIs.
- Optional exact UID filtering through `Calendar::events()`.
- Readonly `Event::$allDay` access alongside `Event::isAllDay()`.
- Generic Property and Component access for repeated, unknown, and non-event data.
- Singular and presence queries for direct calendar components.
- Stable native date intervals for durations derived from event boundaries.
- Safe string, path, stream, and UploadedFile input methods.
- Mapping warnings for unresolved document timezones without silently applying UTC.
- Complete PHPDoc contracts with an automated reflection guard.
- Self-contained interoperability fixtures, bilingual guides, and a repeatable benchmark command.

[0.1.0]: https://github.com/mattmy/laravel-icalendar-reader/releases/tag/v0.1.0
[0.2.0]: https://github.com/mattmy/laravel-icalendar-reader/releases/tag/v0.2.0
