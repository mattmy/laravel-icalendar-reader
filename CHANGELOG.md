# Changelog

All notable changes to this project are documented here. The format follows Keep a Changelog and the project follows Semantic Versioning.

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
