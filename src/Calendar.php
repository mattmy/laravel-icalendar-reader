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
use Mattmy\ICalendar\Exceptions\UnresolvableEventRange;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
use Mattmy\ICalendar\Support\CalendarSerializer;
use Mattmy\ICalendar\Support\EventOccurrenceExpander;
use Mattmy\ICalendar\Support\PropertyName;
use Mattmy\ICalendar\Support\Snapshot;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Represent an queryable calendar with detached public snapshots.
 *
 * @phpstan-import-type ComponentArray from CalendarSerializer
 * @phpstan-import-type CalendarArray from CalendarSerializer
 */
final readonly class Calendar implements JsonSerializable
{
    use QueriesProperties {
        properties as private canonicalProperties;
        property as private canonicalProperty;
    }

    /**
     * Hydrate canonical calendar data and its ordered child data.
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
     * Return detached direct properties, selecting before copying their values.
     *
     * @return Collection<int, Property>
     *
     * @throws InvalidArgumentException
     */
    public function properties(?string $name = null): Collection
    {
        return $this->canonicalProperties($name)->map(Snapshot::property(...));
    }

    /**
     * Return the first detached direct property matching a case-insensitive name.
     *
     * @throws InvalidArgumentException
     */
    public function property(string $name): ?Property
    {
        $first = $this->canonicalProperty($name);

        return $first === null ? null : Snapshot::property($first);
    }

    /**
     * Return events in document order, optionally filtered by exact UID.
     *
     * @return Collection<int, Event>
     */
    public function events(?string $uid = null): Collection
    {
        $events = collect($this->eventItems);

        if ($uid === null) {
            return $events->map(Snapshot::event(...));
        }

        return $events
            ->filter(static fn (Event $event): bool => $event->uid === $uid)
            ->values()
            ->map(Snapshot::event(...));
    }

    /**
     * Determine whether any event, or an exact UID match, exists.
     */
    public function hasEvents(?string $uid = null): bool
    {
        foreach ($this->eventItems as $event) {
            if ($uid === null || $event->uid === $uid) {
                return true;
            }
        }

        return false;
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
                    return Snapshot::event($event);
                }
            }
        }

        return $firstMatch === null ? null : Snapshot::event($firstMatch);
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
            return $todos->map(Snapshot::todo(...));
        }

        return $todos
            ->filter(static fn (Todo $todo): bool => $todo->uid === $uid)
            ->values()
            ->map(Snapshot::todo(...));
    }

    /** Determine whether any todo, or an exact UID match, exists. */
    public function hasTodos(?string $uid = null): bool
    {
        foreach ($this->todoItems as $todo) {
            if ($uid === null || $todo->uid === $uid) {
                return true;
            }
        }

        return false;
    }

    /** Find a todo by its exact, case-sensitive UID. */
    public function todo(string $uid): ?Todo
    {
        $firstMatch = null;

        foreach ($this->todoItems as $todo) {
            if ($todo->uid === $uid) {
                $firstMatch ??= $todo;

                if (! $todo->hasProperty(PropertyName::RECURRENCE_ID)) {
                    return Snapshot::todo($todo);
                }
            }
        }

        return $firstMatch === null ? null : Snapshot::todo($firstMatch);
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
            return $journals->map(Snapshot::journal(...));
        }

        return $journals
            ->filter(static fn (Journal $journal): bool => $journal->uid === $uid)
            ->values()
            ->map(Snapshot::journal(...));
    }

    /** Determine whether any journal, or an exact UID match, exists. */
    public function hasJournals(?string $uid = null): bool
    {
        foreach ($this->journalItems as $journal) {
            if ($uid === null || $journal->uid === $uid) {
                return true;
            }
        }

        return false;
    }

    /** Find a journal by its exact, case-sensitive UID. */
    public function journal(string $uid): ?Journal
    {
        $firstMatch = null;

        foreach ($this->journalItems as $journal) {
            if ($journal->uid === $uid) {
                $firstMatch ??= $journal;

                if (! $journal->hasProperty(PropertyName::RECURRENCE_ID)) {
                    return Snapshot::journal($journal);
                }
            }
        }

        return $firstMatch === null ? null : Snapshot::journal($firstMatch);
    }

    /**
     * Return concrete events overlapping a half-open interval.
     *
     * @return Collection<int, Event>
     *
     * @throws InvalidArgumentException
     * @throws UnresolvableEventRange
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

                if ($start >= $untilTimestamp) {
                    return false;
                }

                if ($event->endsAt === null) {
                    if ($event->startIsDate || $event->hasProperty(PropertyName::DTEND) || $event->hasProperty(PropertyName::DURATION)) {
                        throw new UnresolvableEventRange('An event endpoint required by the range query cannot be resolved safely.');
                    }

                    return $fromTimestamp <= $start;
                }

                return $event->endsAt->getTimestamp() > $fromTimestamp;
            })
            ->values()
            ->map(Snapshot::event(...));
    }

    /**
     * Return non-recurring events and expanded recurring occurrences overlapping a half-open interval.
     *
     * @return Collection<int, Event>
     *
     * @throws InvalidArgumentException
     * @throws RecurrenceLimitExceeded
     * @throws UnresolvableEventRange
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
            return collect($this->componentItems)->map(static fn (Component $component): Component => Snapshot::component($component));
        }

        $name = $this->normalizeName($name);

        return collect($this->componentItems)
            ->filter(static fn (Component $component): bool => $component->name === $name)
            ->values()
            ->map(static fn (Component $component): Component => Snapshot::component($component));
    }

    /**
     * Determine whether any direct child component, or a named one, exists.
     *
     * @throws InvalidArgumentException
     */
    public function hasComponent(?string $name = null): bool
    {
        $name = $name === null ? null : $this->normalizeName($name);

        foreach ($this->componentItems as $component) {
            if ($name === null || $component->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the first direct child component matching a case-insensitive name.
     *
     * @throws InvalidArgumentException
     */
    public function component(string $name): ?Component
    {
        $name = $this->normalizeName($name);

        foreach ($this->componentItems as $component) {
            if ($component->name === $name) {
                return Snapshot::component($component);
            }
        }

        return null;
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
     * @return ComponentArray
     */
    public function toComponentArray(): array
    {
        return $this->serializer->componentArray($this);
    }

    /**
     * Convert the calendar to its current domain-oriented representation.
     *
     * @return CalendarArray
     */
    public function toArray(): array
    {
        return $this->serializer->toArray($this);
    }

    /**
     * Return data suitable for JSON encoding.
     *
     * @return CalendarArray
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
        return \json_encode($this->toArray(), $options | \JSON_THROW_ON_ERROR);
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
     * Normalize and validate an iCalendar component name.
     *
     * @throws InvalidArgumentException
     */
    private function normalizeName(string $name): string
    {
        $name = \trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Component names must not be empty.');
        }

        return \strtoupper($name);
    }
}
