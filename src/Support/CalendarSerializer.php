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
 * @internal
 */
final class CalendarSerializer
{
    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    public function componentArray(Calendar $calendar): array
    {
        return [
            'name' => 'VCALENDAR',
            'properties' => \array_values($calendar->properties()->map(static fn (Property $property): array => $property->toArray())->all()),
            'components' => \array_values($calendar->components()->map(fn (Component $component): array => $this->component($component))->all()),
        ];
    }

    /** @return array<string, mixed> */
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
            'attendees' => $event->attendees->map(fn (Attendee $attendee): array => $this->attendee($attendee))->all(),
            'alarms' => $event->alarms->map(fn (Alarm $alarm): array => $this->alarm($alarm))->all(),
            'categories' => $event->categories->values()->all(),
            'geo' => $event->geo,
            'transparency' => $event->transparency,
            'comments' => $event->comments->values()->all(),
            'contacts' => $event->contacts->values()->all(),
            'resources' => $event->resources->values()->all(),
            'recurrence_rule' => $event->recurrenceRule?->toArray(),
            'attachments' => $this->properties($event->attachments),
            'exception_dates' => $this->properties($event->exceptionDates),
            'request_statuses' => $this->properties($event->requestStatuses),
            'related_to' => $this->properties($event->relatedTo),
            'recurrence_dates' => $this->properties($event->recurrenceDates),
        ];
    }

    /** @return array<string, mixed> */
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
            'attendees' => $todo->attendees->map(fn (Attendee $attendee): array => $this->attendee($attendee))->all(),
            'categories' => $todo->categories->values()->all(), 'alarms' => $todo->alarms->map(fn (Alarm $alarm): array => $this->alarm($alarm))->all(),
            'geo' => $todo->geo, 'comments' => $todo->comments->values()->all(), 'contacts' => $todo->contacts->values()->all(),
            'resources' => $todo->resources->values()->all(), 'recurrence_rule' => $todo->recurrenceRule?->toArray(),
            'attachments' => $this->properties($todo->attachments), 'exception_dates' => $this->properties($todo->exceptionDates),
            'request_statuses' => $this->properties($todo->requestStatuses), 'related_to' => $this->properties($todo->relatedTo),
            'recurrence_dates' => $this->properties($todo->recurrenceDates),
        ];
    }

    /** @return array<string, mixed> */
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
            'attendees' => $journal->attendees->map(fn (Attendee $attendee): array => $this->attendee($attendee))->all(),
            'categories' => $journal->categories->values()->all(), 'comments' => $journal->comments->values()->all(),
            'contacts' => $journal->contacts->values()->all(), 'descriptions' => $journal->descriptions->values()->all(),
            'exception_dates' => $this->properties($journal->exceptionDates), 'related_to' => $this->properties($journal->relatedTo),
            'recurrence_dates' => $this->properties($journal->recurrenceDates), 'request_statuses' => $this->properties($journal->requestStatuses),
        ];
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
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
            'delegated_from' => $attendee->delegatedFrom->values()->all(),
            'delegated_to' => $attendee->delegatedTo->values()->all(),
            'parameters' => $attendee->parameters(),
        ];
    }

    /** @return array<string, mixed> */
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
            'attendees' => $alarm->attendees->map(fn (Attendee $attendee): array => $this->attendee($attendee))->all(),
            'attachments' => $this->properties($alarm->attachments),
            'repeat' => $alarm->repeat,
            'duration' => $this->duration($alarm->duration),
        ];
    }

    /** @return array<string, mixed> */
    private function component(Component $component): array
    {
        return [
            'name' => $component->name,
            'properties' => $component->properties()->map(static fn (Property $property): array => $property->toArray())->all(),
            'components' => $component->components()->map(fn (Component $child): array => $this->component($child))->all(),
        ];
    }

    /**
     * @param  Collection<int, Property>  $properties
     * @return list<array<string, mixed>>
     */
    private function properties(Collection $properties): array
    {
        return \array_values($properties->map(static fn (Property $property): array => $property->toArray())->all());
    }

    /**
     * @param  CarbonImmutable|null  $value
     */
    private function dateTime(?CarbonImmutable $value, bool $isDate): ?string
    {
        return $value === null ? null : ($isDate ? $value->toDateString() : $value->toIso8601String());
    }

    /**
     * @param  DateInterval|null  $duration
     */
    private function duration(?DateInterval $duration): ?string
    {
        if ($duration === null) {
            return null;
        } $date = ($duration->y ? $duration->y . 'Y' : '') . ($duration->m ? $duration->m . 'M' : '') . ($duration->d ? $duration->d . 'D' : '');
        $time = ($duration->h ? $duration->h . 'H' : '') . ($duration->i ? $duration->i . 'M' : '') . ($duration->s ? $duration->s . 'S' : '');

        return ($duration->invert ? '-' : '') . 'P' . ($date === '' && $time === '' ? '0D' : $date) . ($time === '' ? '' : 'T' . $time);
    }
}
