# Changelog

All notable changes to this project are documented here. The format follows Keep a Changelog and the project follows Semantic Versioning.

## [Unreleased]

### Added

- Strict iCalendar parsing, full Sabre/VObject validation, and structured issues.
- Typed Calendar, Event, Organizer, Attendee, Alarm, and AlarmTrigger APIs.
- Readonly `Event::$allDay` access alongside `Event::isAllDay()`.
- Generic Property and Component access for repeated, unknown, and non-event data.
- Singular and presence queries for direct calendar components.
- Stable native date intervals for durations derived from event boundaries.
- Safe string, path, stream, and UploadedFile input methods.
- Mapping warnings for unresolved document timezones without silently applying UTC.
- Complete PHPDoc contracts with an automated reflection guard.
- Self-contained interoperability fixtures, bilingual guides, and a repeatable benchmark command.
