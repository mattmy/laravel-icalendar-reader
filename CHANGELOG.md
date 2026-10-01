# Changelog

All notable changes to this project are documented here. The format follows Keep a Changelog and the project follows Semantic Versioning.

## [0.6.0] - 2026-10-01

### Fixed

- Reject incomplete calendars, dates and times that do not exist, malformed durations,
  incorrect date/time value types, and invalid recurrence counts before creating read models.
- Use the calendar's own timezone rules when checking that an event or task ends after it starts,
  so validation agrees with the dates returned by the reader.
- Keep properties and components in their original order, even when names are mixed or repeated.
- Fix missing recurring events at the start of a query range, including events with no duration,
  RDATE entries listed out of order, and rescheduled events moved into or lasting into the range.
- Follow the calendar's VTIMEZONE rules and UTC UNTIL cutoff when expanding local recurring events.
  Report unsupported recurrence options instead of silently ignoring them.
- Update formatting rules and array/JSON type documentation to match the project's PHP 8.2 standards.

## [0.5.0] - 2026-08-31

### Changed

- Lowered the minimum PHP version from 8.3 to 8.2 while retaining Laravel 11, 12, and 13 support.

### Added

- Added native PHP 8.2 CI coverage for Laravel 11, Laravel 12, quality checks, and lowest
  dependencies.
- Added regression coverage for the Composer PHP 8.2 platform constraint.

### Fixed

- Removed PHP 8.3-only typed class constants and `#[Override]` attributes while preserving the
  same public API and runtime behavior.

## [0.4.0] - 2026-08-31

### Changed

- Moved calendar hydration, property and date-time mapping, recurrence expansion, and
  serialization into focused internal modules without changing public behavior.

### Fixed

- Applied the mandatory PHP 8.3 `#[Override]` attributes and normalized the existing Pint
  formatting baseline.
- Replaced the CI Cartesian product with explicit supported PHP, Laravel, and Testbench jobs.
- Updated the benchmark bootstrap for the extracted hydration modules.
- Synchronized the standalone bilingual documentation for Journal serialization and Alarm
  property/raw-component access.

## [0.3.0] - 2026-08-14

### Added

- Typed `Journal` snapshots with exact UID queries, repeated descriptions, direct properties,
  defensive raw-component clones, and Calendar array/JSON serialization.
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
[0.3.0]: https://github.com/mattmy/laravel-icalendar-reader/releases/tag/v0.3.0
[0.4.0]: https://github.com/mattmy/laravel-icalendar-reader/releases/tag/v0.4.0
[0.5.0]: https://github.com/mattmy/laravel-icalendar-reader/releases/tag/v0.5.0
[0.6.0]: https://github.com/mattmy/laravel-icalendar-reader/releases/tag/v0.6.0
[Unreleased]: https://github.com/mattmy/laravel-icalendar-reader/compare/v0.5.0...HEAD
