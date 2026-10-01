<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Support\Collection;
use Mattmy\ICalendar\Alarm;
use Mattmy\ICalendar\Attendee;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\Component;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Journal;
use Mattmy\ICalendar\Organizer;
use Mattmy\ICalendar\Property;
use Mattmy\ICalendar\Todo;

/**
 * Export domain snapshots and the complete normalized component tree.
 *
 * @phpstan-type ParameterMap array<string, string|list<string>>
 *
 * @phpstan-import-type PropertyArray from Property
 *
 * @phpstan-type ComponentArray array{name: string, properties: list<PropertyArray>, components: list<array<string, mixed>>}
 * @phpstan-type IssueArray array{level: int, code: string, message: string, source: string, line: ?int, component: ?string, property: ?string}
 * @phpstan-type OrganizerArray array{address: string, email: ?string, name: ?string, sent_by: ?string, directory: ?string, parameters: ParameterMap}
 * @phpstan-type AttendeeArray array{address: string, email: ?string, name: ?string, role: ?string, status: ?string, rsvp: ?bool, type: ?string, delegated_from: list<string>, delegated_to: list<string>, parameters: ParameterMap}
 * @phpstan-type AlarmTriggerArray array{is_relative: bool, is_absolute: bool, duration: ?string, date_time: ?string, related_to: ?string}
 * @phpstan-type AlarmArray array{action: ?string, trigger: ?AlarmTriggerArray, description: ?string, summary: ?string, attendees: list<AttendeeArray>, attachments: list<PropertyArray>, repeat: ?int, duration: ?string}
 * @phpstan-type GeoArray array{latitude: float, longitude: float}
 * @phpstan-type EventArray array{uid: ?string, summary: ?string, description: ?string, location: ?string, starts_at: ?string, ends_at: ?string, start_is_date: bool, end_is_date: bool, start_is_floating: bool, end_is_floating: bool, is_all_day: bool, last_day: ?string, duration: ?string, timestamp: ?string, created_at: ?string, last_modified_at: ?string, status: ?string, classification: ?string, priority: ?int, recurrence_id: ?string, recurrence_id_is_date: bool, recurrence_id_is_floating: bool, sequence: ?int, url: ?string, organizer: ?OrganizerArray, attendees: list<AttendeeArray>, alarms: list<AlarmArray>, categories: list<string>, geo: ?GeoArray, transparency: ?string, comments: list<string>, contacts: list<string>, resources: list<string>, recurrence_rule: ?PropertyArray, attachments: list<PropertyArray>, exception_dates: list<PropertyArray>, request_statuses: list<PropertyArray>, related_to: list<PropertyArray>, recurrence_dates: list<PropertyArray>}
 * @phpstan-type TodoArray array{uid: ?string, timestamp: ?string, classification: ?string, completed_at: ?string, created_at: ?string, description: ?string, starts_at: ?string, start_is_date: bool, start_is_floating: bool, due_at: ?string, due_is_date: bool, due_is_floating: bool, duration: ?string, last_modified_at: ?string, location: ?string, organizer: ?OrganizerArray, percent_complete: ?int, priority: ?int, recurrence_id: ?string, recurrence_id_is_date: bool, recurrence_id_is_floating: bool, sequence: ?int, status: ?string, summary: ?string, url: ?string, attendees: list<AttendeeArray>, categories: list<string>, alarms: list<AlarmArray>, geo: ?GeoArray, comments: list<string>, contacts: list<string>, resources: list<string>, recurrence_rule: ?PropertyArray, attachments: list<PropertyArray>, exception_dates: list<PropertyArray>, request_statuses: list<PropertyArray>, related_to: list<PropertyArray>, recurrence_dates: list<PropertyArray>}
 * @phpstan-type JournalArray array{uid: ?string, timestamp: ?string, classification: ?string, created_at: ?string, starts_at: ?string, start_is_date: bool, start_is_floating: bool, last_modified_at: ?string, organizer: ?OrganizerArray, recurrence_id: ?string, recurrence_id_is_date: bool, recurrence_id_is_floating: bool, sequence: ?int, status: ?string, summary: ?string, url: ?string, recurrence_rule: ?PropertyArray, attachments: list<PropertyArray>, attendees: list<AttendeeArray>, categories: list<string>, comments: list<string>, contacts: list<string>, descriptions: list<string>, exception_dates: list<PropertyArray>, related_to: list<PropertyArray>, recurrence_dates: list<PropertyArray>, request_statuses: list<PropertyArray>}
 * @phpstan-type CalendarArray array{version: ?string, product_id: ?string, method: ?string, calendar_scale: ?string, floating_timezone: string, events: list<EventArray>, todos: list<TodoArray>, journals: list<JournalArray>, warnings: list<IssueArray>}
 *
 * @internal
 */
final class CalendarSerializer
{
    /**
     * Export the fixed domain-oriented calendar representation.
     *
     * @return CalendarArray
     */
    public function toArray(Calendar $calendar): array
    {
        return [
            'version' => $calendar->version,
            'product_id' => $calendar->productId,
            'method' => $calendar->method,
            'calendar_scale' => $calendar->calendarScale,
            'floating_timezone' => $calendar->floatingTimezone,
            'events' => \array_values($calendar->events()->map(fn (Event $event): array => $this->event($event))->all()),
            'todos' => \array_values($calendar->todos()->map(fn (Todo $todo): array => $this->todo($todo))->all()),
            'journals' => \array_values($calendar->journals()->map(fn (Journal $journal): array => $this->journal($journal))->all()),
            'warnings' => \array_values($calendar->warnings()->map(static fn ($issue): array => $issue->toArray())->all()),
        ];
    }

    /**
     * Export every ordered property and component without collapsing unknown data.
     *
     * @return ComponentArray
     */
    public function componentArray(Calendar $calendar): array
    {
        return [
            'name' => 'VCALENDAR',
            'properties' => \array_values($calendar->properties()->map(static fn (Property $property): array => $property->toArray())->all()),
            'components' => \array_values($calendar->components()->map(fn (Component $component): array => $this->component($component))->all()),
        ];
    }

    /**
     * Export one event using the shared leaf mappings.
     *
     * @return EventArray
     */
    private function event(Event $event): array
    {
        return [
            'uid' => $event->uid,
            'summary' => $event->summary,
            'description' => $event->description,
            'location' => $event->location,
            'starts_at' => $this->dateTime($event->startsAt, $event->startIsDate),
            'ends_at' => $this->dateTime($event->endsAt, $event->endIsDate),
            'start_is_date' => $event->startIsDate,
            'end_is_date' => $event->endIsDate,
            'start_is_floating' => $event->startIsFloating,
            'end_is_floating' => $event->endIsFloating,
            'is_all_day' => $event->allDay,
            'last_day' => $event->lastDay?->toDateString(),
            'duration' => $this->duration($event->duration),
            'timestamp' => $event->timestamp?->toIso8601String(),
            'created_at' => $event->createdAt?->toIso8601String(),
            'last_modified_at' => $event->lastModifiedAt?->toIso8601String(),
            'status' => $event->status,
            'classification' => $event->classification,
            'priority' => $event->priority,
            'recurrence_id' => $this->dateTime($event->recurrenceId, $event->recurrenceIdIsDate),
            'recurrence_id_is_date' => $event->recurrenceIdIsDate,
            'recurrence_id_is_floating' => $event->recurrenceIdIsFloating,
            'sequence' => $event->sequence,
            'url' => $event->url,
            'organizer' => $event->organizer === null ? null : $this->organizer($event->organizer),
            'attendees' => \array_values($event->attendees->map(fn (Attendee $attendee): array => $this->attendee($attendee))->all()),
            'alarms' => \array_values($event->alarms->map(fn (Alarm $alarm): array => $this->alarm($alarm))->all()),
            'categories' => \array_values($event->categories->all()),
            'geo' => $event->geo,
            'transparency' => $event->transparency,
            'comments' => \array_values($event->comments->all()),
            'contacts' => \array_values($event->contacts->all()),
            'resources' => \array_values($event->resources->all()),
            'recurrence_rule' => $event->recurrenceRule?->toArray(),
            'attachments' => $this->properties($event->attachments),
            'exception_dates' => $this->properties($event->exceptionDates),
            'request_statuses' => $this->properties($event->requestStatuses),
            'related_to' => $this->properties($event->relatedTo),
            'recurrence_dates' => $this->properties($event->recurrenceDates),
        ];
    }

    /**
     * Export one todo using the shared leaf mappings.
     *
     * @return TodoArray
     */
    private function todo(Todo $todo): array
    {
        return [
            'uid' => $todo->uid, 'timestamp' => $todo->timestamp?->toIso8601String(), 'classification' => $todo->classification,
            'completed_at' => $todo->completedAt?->toIso8601String(), 'created_at' => $todo->createdAt?->toIso8601String(),
            'description' => $todo->description, 'starts_at' => $this->dateTime($todo->startsAt, $todo->startIsDate),
            'start_is_date' => $todo->startIsDate, 'start_is_floating' => $todo->startIsFloating,
            'due_at' => $this->dateTime($todo->dueAt, $todo->dueIsDate), 'due_is_date' => $todo->dueIsDate,
            'due_is_floating' => $todo->dueIsFloating, 'duration' => $this->duration($todo->duration),
            'last_modified_at' => $todo->lastModifiedAt?->toIso8601String(), 'location' => $todo->location,
            'organizer' => $todo->organizer === null ? null : $this->organizer($todo->organizer),
            'percent_complete' => $todo->percentComplete, 'priority' => $todo->priority,
            'recurrence_id' => $this->dateTime($todo->recurrenceId, $todo->recurrenceIdIsDate),
            'recurrence_id_is_date' => $todo->recurrenceIdIsDate, 'recurrence_id_is_floating' => $todo->recurrenceIdIsFloating,
            'sequence' => $todo->sequence, 'status' => $todo->status, 'summary' => $todo->summary, 'url' => $todo->url,
            'attendees' => \array_values($todo->attendees->map(fn (Attendee $attendee): array => $this->attendee($attendee))->all()),
            'categories' => \array_values($todo->categories->all()), 'alarms' => \array_values($todo->alarms->map(fn (Alarm $alarm): array => $this->alarm($alarm))->all()),
            'geo' => $todo->geo, 'comments' => \array_values($todo->comments->all()), 'contacts' => \array_values($todo->contacts->all()),
            'resources' => \array_values($todo->resources->all()), 'recurrence_rule' => $todo->recurrenceRule?->toArray(),
            'attachments' => $this->properties($todo->attachments), 'exception_dates' => $this->properties($todo->exceptionDates),
            'request_statuses' => $this->properties($todo->requestStatuses), 'related_to' => $this->properties($todo->relatedTo),
            'recurrence_dates' => $this->properties($todo->recurrenceDates),
        ];
    }

    /**
     * Export one journal while retaining repeated descriptions.
     *
     * @return JournalArray
     */
    private function journal(Journal $journal): array
    {
        return [
            'uid' => $journal->uid, 'timestamp' => $journal->timestamp?->toIso8601String(), 'classification' => $journal->classification,
            'created_at' => $journal->createdAt?->toIso8601String(), 'starts_at' => $this->dateTime($journal->startsAt, $journal->startIsDate),
            'start_is_date' => $journal->startIsDate, 'start_is_floating' => $journal->startIsFloating,
            'last_modified_at' => $journal->lastModifiedAt?->toIso8601String(),
            'organizer' => $journal->organizer === null ? null : $this->organizer($journal->organizer),
            'recurrence_id' => $this->dateTime($journal->recurrenceId, $journal->recurrenceIdIsDate),
            'recurrence_id_is_date' => $journal->recurrenceIdIsDate, 'recurrence_id_is_floating' => $journal->recurrenceIdIsFloating,
            'sequence' => $journal->sequence, 'status' => $journal->status, 'summary' => $journal->summary, 'url' => $journal->url,
            'recurrence_rule' => $journal->recurrenceRule?->toArray(), 'attachments' => $this->properties($journal->attachments),
            'attendees' => \array_values($journal->attendees->map(fn (Attendee $attendee): array => $this->attendee($attendee))->all()),
            'categories' => \array_values($journal->categories->all()), 'comments' => \array_values($journal->comments->all()),
            'contacts' => \array_values($journal->contacts->all()), 'descriptions' => \array_values($journal->descriptions->all()),
            'exception_dates' => $this->properties($journal->exceptionDates), 'related_to' => $this->properties($journal->relatedTo),
            'recurrence_dates' => $this->properties($journal->recurrenceDates), 'request_statuses' => $this->properties($journal->requestStatuses),
        ];
    }

    /**
     * Export an organizer and its complete parameters.
     *
     * @return OrganizerArray
     */
    private function organizer(Organizer $organizer): array
    {
        return [
            'address' => $organizer->address,
            'email' => $organizer->email,
            'name' => $organizer->name,
            'sent_by' => $organizer->sentBy,
            'directory' => $organizer->directory,
            'parameters' => $organizer->parameters(),
        ];
    }

    /**
     * Export an attendee and its delegation parameters.
     *
     * @return AttendeeArray
     */
    private function attendee(Attendee $attendee): array
    {
        return [
            'address' => $attendee->address,
            'email' => $attendee->email,
            'name' => $attendee->name,
            'role' => $attendee->role,
            'status' => $attendee->status,
            'rsvp' => $attendee->rsvp,
            'type' => $attendee->type,
            'delegated_from' => \array_values($attendee->delegatedFrom->all()),
            'delegated_to' => \array_values($attendee->delegatedTo->all()),
            'parameters' => $attendee->parameters(),
        ];
    }

    /**
     * Export an alarm and its relative or absolute trigger.
     *
     * @return AlarmArray
     */
    private function alarm(Alarm $alarm): array
    {
        return [
            'action' => $alarm->action,
            'trigger' => $alarm->trigger === null ? null : [
                'is_relative' => $alarm->trigger->isRelative(),
                'is_absolute' => $alarm->trigger->isAbsolute(),
                'duration' => $this->duration($alarm->trigger->duration()),
                'date_time' => $alarm->trigger->dateTime()?->toIso8601String(),
                'related_to' => $alarm->trigger->relatedTo(),
            ],
            'description' => $alarm->description,
            'summary' => $alarm->summary,
            'attendees' => \array_values($alarm->attendees->map(fn (Attendee $attendee): array => $this->attendee($attendee))->all()),
            'attachments' => $this->properties($alarm->attachments),
            'repeat' => $alarm->repeat,
            'duration' => $this->duration($alarm->duration),
        ];
    }

    /**
     * Recursively export a normalized component and its direct children.
     *
     * @return ComponentArray
     */
    private function component(Component $component): array
    {
        return [
            'name' => $component->name,
            'properties' => \array_values($component->properties()->map(static fn (Property $property): array => $property->toArray())->all()),
            'components' => \array_values($component->components()->map(fn (Component $child): array => $this->component($child))->all()),
        ];
    }

    /**
     * Export an ordered list of complete property representations.
     *
     * @param  Collection<int, Property>  $properties
     * @return list<PropertyArray>
     */
    private function properties(Collection $properties): array
    {
        return \array_values($properties->map(static fn (Property $property): array => $property->toArray())->all());
    }

    /** Format a typed date without losing its DATE versus DATE-TIME distinction. */
    private function dateTime(?CarbonImmutable $value, bool $isDate): ?string
    {
        return $value === null ? null : ($isDate ? $value->toDateString() : $value->toIso8601String());
    }

    /** Format a duration using the established iCalendar-style representation. */
    private function duration(?DateInterval $duration): ?string
    {
        if ($duration === null) {
            return null;
        }

        $date = ($duration->y ? $duration->y . 'Y' : '') . ($duration->m ? $duration->m . 'M' : '') . ($duration->d ? $duration->d . 'D' : '');
        $time = ($duration->h ? $duration->h . 'H' : '') . ($duration->i ? $duration->i . 'M' : '') . ($duration->s ? $duration->s . 'S' : '');

        return ($duration->invert ? '-' : '') . 'P' . ($date === '' && $time === '' ? '0D' : $date) . ($time === '' ? '' : 'T' . $time);
    }
}
