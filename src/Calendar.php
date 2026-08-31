<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Mattmy\ICalendar\Concerns\QueriesProperties;
use Mattmy\ICalendar\Exceptions\RecurrenceLimitExceeded;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
use Mattmy\ICalendar\Support\CalendarSerializer;
use Mattmy\ICalendar\Support\EventOccurrenceExpander;
use Mattmy\ICalendar\Support\PropertyName;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Represent an immutable, queryable snapshot of one VCALENDAR document.
 *
 * @phpstan-type ParameterMap array<string, string|list<string>>
 * @phpstan-import-type PropertyArray from Property
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
 */
final readonly class Calendar implements JsonSerializable
{
    use QueriesProperties;

    /**
     * Hydrate an immutable calendar snapshot and its ordered child data.
     *
     * @param  list<Event>  $eventItems
     * @param  list<Todo>  $todoItems
     * @param  list<Journal>  $journalItems
     * @param  list<CalendarIssue>  $warningItems
     * @param  list<Property>  $propertyItems
     * @param  list<Component>  $componentItems
     * @param  Closure(VEvent): Event  $eventHydrator
     *
     * @internal
     */
    public function __construct(
        public ?string $version,
        public ?string $productId,
        public ?string $method,
        public ?string $calendarScale,
        public string $floatingTimezone,
        private array $eventItems,
        private array $todoItems,
        private array $journalItems,
        private array $warningItems,
        private array $propertyItems,
        private array $componentItems,
        private VCalendar $component,
        private Closure $eventHydrator,
        private CalendarSerializer $serializer = new CalendarSerializer(),
        private EventOccurrenceExpander $occurrenceExpander = new EventOccurrenceExpander(),
    ) {}

    /**
     * Return events in document order, optionally filtered by exact UID.
     *
     * @return Collection<int, Event>
     */
    public function events(?string $uid = null): Collection
    {
        $events = collect($this->eventItems);

        if ($uid === null) {
            return $events;
        }

        return $events
            ->filter(static fn (Event $event): bool => $event->uid === $uid)
            ->values();
    }

    /**
     * Determine whether any event, or an exact UID match, exists.
     */
    public function hasEvents(?string $uid = null): bool
    {
        return $this->events($uid)->isNotEmpty();
    }

    /**
     * Find an event by its exact, case-sensitive UID.
     */
    public function event(string $uid): ?Event
    {
        $firstMatch = null;

        foreach ($this->eventItems as $event) {
            if ($event->uid === $uid) {
                $firstMatch ??= $event;

                if (! $event->hasProperty(PropertyName::RECURRENCE_ID)) {
                    return $event;
                }
            }
        }

        return $firstMatch;
    }

    /**
     * Return todos in document order, optionally filtered by exact UID.
     *
     * @return Collection<int, Todo>
     */
    public function todos(?string $uid = null): Collection
    {
        $todos = collect($this->todoItems);

        if ($uid === null) {
            return $todos;
        }

        return $todos
            ->filter(static fn (Todo $todo): bool => $todo->uid === $uid)
            ->values();
    }

    /** Determine whether any todo, or an exact UID match, exists. */
    public function hasTodos(?string $uid = null): bool
    {
        return $this->todos($uid)->isNotEmpty();
    }

    /** Find a todo by its exact, case-sensitive UID. */
    public function todo(string $uid): ?Todo
    {
        $firstMatch = null;

        foreach ($this->todoItems as $todo) {
            if ($todo->uid === $uid) {
                $firstMatch ??= $todo;

                if (! $todo->hasProperty(PropertyName::RECURRENCE_ID)) {
                    return $todo;
                }
            }
        }

        return $firstMatch;
    }

    /**
     * Return journals in document order, optionally filtered by exact UID.
     *
     * @return Collection<int, Journal>
     */
    public function journals(?string $uid = null): Collection
    {
        $journals = collect($this->journalItems);

        if ($uid === null) {
            return $journals;
        }

        return $journals
            ->filter(static fn (Journal $journal): bool => $journal->uid === $uid)
            ->values();
    }

    /** Determine whether any journal, or an exact UID match, exists. */
    public function hasJournals(?string $uid = null): bool
    {
        return $this->journals($uid)->isNotEmpty();
    }

    /** Find a journal by its exact, case-sensitive UID. */
    public function journal(string $uid): ?Journal
    {
        $firstMatch = null;

        foreach ($this->journalItems as $journal) {
            if ($journal->uid === $uid) {
                $firstMatch ??= $journal;

                if (! $journal->hasProperty(PropertyName::RECURRENCE_ID)) {
                    return $journal;
                }
            }
        }

        return $firstMatch;
    }

    /**
     * Return concrete events overlapping a half-open interval.
     *
     * @return Collection<int, Event>
     *
     * @throws InvalidArgumentException
     */
    public function eventsBetween(DateTimeInterface $from, DateTimeInterface $until): Collection
    {
        $fromTimestamp = $from->getTimestamp();
        $untilTimestamp = $until->getTimestamp();

        if ($fromTimestamp >= $untilTimestamp) {
            throw new InvalidArgumentException('The event range start must be before its end.');
        }

        return collect($this->eventItems)
            ->filter(static function (Event $event) use ($fromTimestamp, $untilTimestamp): bool {
                if ($event->startsAt === null) {
                    return false;
                }

                $start = $event->startsAt->getTimestamp();

                if ($event->endsAt === null) {
                    return $fromTimestamp <= $start && $start < $untilTimestamp;
                }

                return $start < $untilTimestamp && $event->endsAt->getTimestamp() > $fromTimestamp;
            })
            ->values();
    }

    /**
     * Return non-recurring events and expanded recurring occurrences overlapping a half-open interval.
     *
     * @return Collection<int, Event>
     *
     * @throws InvalidArgumentException
     * @throws RecurrenceLimitExceeded
     * @throws UnsupportedRecurrence
     */
    public function occurrencesBetween(DateTimeInterface $from, DateTimeInterface $until): Collection
    {
        $fromTimestamp = $from->getTimestamp();
        $untilTimestamp = $until->getTimestamp();

        if ($fromTimestamp >= $untilTimestamp) {
            throw new InvalidArgumentException('The occurrence range start must be before its end.');
        }

        return $this->occurrenceExpander->expand(
            calendar: $this->component,
            eventHydrator: $this->eventHydrator,
            floatingTimezone: $this->floatingTimezone,
            fromTimestamp: $fromTimestamp,
            untilTimestamp: $untilTimestamp,
        );
    }

    /**
     * Return non-fatal parsing, validation, configuration, and mapping issues.
     *
     * @return Collection<int, CalendarIssue>
     */
    public function warnings(): Collection
    {
        return collect($this->warningItems);
    }

    /**
     * Return direct child components, optionally filtered case-insensitively by name.
     *
     * @return Collection<int, Component>
     *
     * @throws InvalidArgumentException
     */
    public function components(?string $name = null): Collection
    {
        if ($name === null) {
            return collect($this->componentItems);
        }

        $name = $this->normalizeName($name, 'Component');

        return collect($this->componentItems)
            ->filter(static fn (Component $component): bool => $component->name === $name)
            ->values();
    }

    /**
     * Determine whether any direct child component, or a named one, exists.
     *
     * @throws InvalidArgumentException
     */
    public function hasComponent(?string $name = null): bool
    {
        return $this->components($name)->isNotEmpty();
    }

    /**
     * Return the first direct child component matching a case-insensitive name.
     *
     * @throws InvalidArgumentException
     */
    public function component(string $name): ?Component
    {
        return $this->components($name)->first();
    }

    /**
     * Return a deep clone of the underlying low-level calendar component.
     */
    public function rawComponent(): VCalendar
    {
        return clone $this->component;
    }

    /**
     * Export the complete normalized component tree without collapsing repeated data.
     *
     * @return array<string, mixed>
     */
    public function toComponentArray(): array
    {
        return $this->serializer->componentArray($this);
    }

    /**
     * Convert the calendar to its current domain-oriented representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->serializer->toArray($this);
    }

    /**
     * Return data suitable for JSON encoding.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Encode the domain-oriented representation as JSON with throwing error semantics.
     *
     * @throws JsonException
     */
    public function toJson(int $options = 0): string
    {
        return \json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Return the calendar's ordered direct properties for the internal query trait.
     *
     * @return list<Property>
     *
     * @internal
     */
    protected function propertyItems(): array
    {
        return $this->propertyItems;
    }

    /**
     * Normalize and validate an iCalendar property or component name.
     *
     * @throws InvalidArgumentException
     */
    private function normalizeName(string $name, string $kind): string
    {
        $name = \trim($name);

        if ($name === '') {
            throw new InvalidArgumentException("{$kind} names must not be empty.");
        }

        return \strtoupper($name);
    }
}
