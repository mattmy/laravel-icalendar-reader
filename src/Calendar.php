<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Sabre\VObject\Component\VCalendar;

/**
 * Represent an immutable, queryable snapshot of one VCALENDAR document.
 *
 * @phpstan-type ParameterMap array<string, string|list<string>>
 * @phpstan-type PropertyAtom bool|int|float|string|\Carbon\CarbonImmutable|DateInterval
 * @phpstan-type PropertyValue PropertyAtom|list<PropertyAtom>|null
 * @phpstan-type PropertyArray array{name: string, type: string, value: PropertyValue, values: list<PropertyAtom>, parameters: ParameterMap, raw_value: string}
 * @phpstan-type IssueArray array{level: int, code: string, message: string, source: string, line: ?int, component: ?string, property: ?string}
 * @phpstan-type OrganizerArray array{address: string, email: ?string, name: ?string, sent_by: ?string, directory: ?string, parameters: ParameterMap}
 * @phpstan-type AttendeeArray array{address: string, email: ?string, name: ?string, role: ?string, status: ?string, rsvp: ?bool, type: ?string, delegated_from: list<string>, delegated_to: list<string>, parameters: ParameterMap}
 * @phpstan-type AlarmTriggerArray array{is_relative: bool, is_absolute: bool, duration: ?string, date_time: ?string, related_to: ?string}
 * @phpstan-type AlarmArray array{action: ?string, trigger: ?AlarmTriggerArray, description: ?string, summary: ?string, attendees: list<AttendeeArray>, repeat: ?int, duration: ?string}
 * @phpstan-type EventArray array{uid: ?string, summary: ?string, description: ?string, location: ?string, starts_at: ?string, ends_at: ?string, start_is_floating: bool, end_is_floating: bool, is_all_day: bool, last_day: ?string, duration: ?string, timestamp: ?string, created_at: ?string, last_modified_at: ?string, status: ?string, classification: ?string, priority: ?int, sequence: ?int, url: ?string, organizer: ?OrganizerArray, attendees: list<AttendeeArray>, alarms: list<AlarmArray>, categories: list<string>}
 * @phpstan-type CalendarArray array{version: ?string, product_id: ?string, method: ?string, calendar_scale: ?string, floating_timezone: string, events: list<EventArray>, warnings: list<IssueArray>}
 */
final readonly class Calendar implements JsonSerializable
{
    /**
     * Hydrate an immutable calendar snapshot and its ordered child data.
     *
     * @param  list<Event>  $eventItems
     * @param  list<CalendarIssue>  $warningItems
     * @param  list<Property>  $propertyItems
     * @param  list<Component>  $componentItems
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
        private array $warningItems,
        private array $propertyItems,
        private array $componentItems,
        private VCalendar $component,
    ) {}

    /**
     * Return events in document order, optionally filtered by exact summary.
     *
     * @return Collection<int, Event>
     */
    public function events(?string $eventName = null): Collection
    {
        $events = collect($this->eventItems);

        if ($eventName === null) {
            return $events;
        }

        return $events
            ->filter(static fn (Event $event): bool => $event->summary === $eventName)
            ->values();
    }

    /**
     * Determine whether any event, or an exact summary match, exists.
     */
    public function hasEvents(?string $name = null): bool
    {
        return $this->events($name)->isNotEmpty();
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

                if (! $event->hasProperty('RECURRENCE-ID')) {
                    return $event;
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
     * Return non-fatal parsing, validation, configuration, and mapping issues.
     *
     * @return Collection<int, CalendarIssue>
     */
    public function warnings(): Collection
    {
        return collect($this->warningItems);
    }

    /**
     * Return direct calendar properties, optionally filtered case-insensitively by name.
     *
     * @return Collection<int, Property>
     *
     * @throws InvalidArgumentException
     */
    public function properties(?string $name = null): Collection
    {
        if ($name === null) {
            return collect($this->propertyItems);
        }

        $name = $this->normalizeName($name, 'Property');

        return collect($this->propertyItems)
            ->filter(static fn (Property $property): bool => $property->name === $name)
            ->values();
    }

    /**
     * Determine whether any direct property, or a named direct property, exists.
     *
     * @throws InvalidArgumentException
     */
    public function hasProperty(?string $name = null): bool
    {
        return $this->properties($name)->isNotEmpty();
    }

    /**
     * Return the first direct property matching a case-insensitive name.
     *
     * @throws InvalidArgumentException
     */
    public function property(string $name): ?Property
    {
        return $this->properties($name)->first();
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
     * @return array{name: string, properties: list<array<string, mixed>>, components: list<array<string, mixed>>}
     */
    public function toComponentArray(): array
    {
        return [
            'name' => 'VCALENDAR',
            'properties' => \array_map(
                fn (Property $property): array => $this->propertyArray($property),
                $this->propertyItems,
            ),
            'components' => \array_map(
                fn (Component $component): array => $this->componentArray($component),
                $this->componentItems,
            ),
        ];
    }

    /**
     * Convert the calendar to its current domain-oriented representation.
     *
     * @return CalendarArray
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'product_id' => $this->productId,
            'method' => $this->method,
            'calendar_scale' => $this->calendarScale,
            'floating_timezone' => $this->floatingTimezone,
            'events' => \array_map(
                fn (Event $event): array => $this->eventArray($event),
                $this->eventItems,
            ),
            'warnings' => \array_map(
                static fn (CalendarIssue $issue): array => $issue->toArray(),
                $this->warningItems,
            ),
        ];
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
        return \json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Convert one event for the Calendar output contract.
     *
     * @return EventArray
     */
    private function eventArray(Event $event): array
    {
        return [
            'uid' => $event->uid,
            'summary' => $event->summary,
            'description' => $event->description,
            'location' => $event->location,
            'starts_at' => $event->startsAt?->toIso8601String(),
            'ends_at' => $event->endsAt?->toIso8601String(),
            'start_is_floating' => $event->startIsFloating,
            'end_is_floating' => $event->endIsFloating,
            'is_all_day' => $event->allDay,
            'last_day' => $event->lastDay?->toDateString(),
            'duration' => self::durationString($event->duration),
            'timestamp' => $event->timestamp?->toIso8601String(),
            'created_at' => $event->createdAt?->toIso8601String(),
            'last_modified_at' => $event->lastModifiedAt?->toIso8601String(),
            'status' => $event->status,
            'classification' => $event->classification,
            'priority' => $event->priority,
            'sequence' => $event->sequence,
            'url' => $event->url,
            'organizer' => $event->organizer === null ? null : $this->organizerArray($event->organizer),
            'attendees' => \array_values($event->attendees->map(fn (Attendee $attendee): array => $this->attendeeArray($attendee))->all()),
            'alarms' => \array_values($event->alarms->map(fn (Alarm $alarm): array => $this->alarmArray($alarm))->all()),
            'categories' => \array_values($event->categories->all()),
        ];
    }

    /**
     * Convert an organizer for the Calendar output contract.
     *
     * @return OrganizerArray
     */
    private function organizerArray(Organizer $organizer): array
    {
        return [
            'address' => $organizer->address,
            'email' => $organizer->email,
            'name' => $organizer->name,
            'sent_by' => $organizer->sentBy,
            'directory' => $organizer->directory,
            'parameters' => $this->parameterArray($organizer->parameters()),
        ];
    }

    /**
     * Convert an attendee for the Calendar output contract.
     *
     * @return AttendeeArray
     */
    private function attendeeArray(Attendee $attendee): array
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
            'parameters' => $this->parameterArray($attendee->parameters()),
        ];
    }

    /**
     * Convert an alarm for the Calendar output contract.
     *
     * @return AlarmArray
     */
    private function alarmArray(Alarm $alarm): array
    {
        return [
            'action' => $alarm->action,
            'trigger' => $alarm->trigger === null ? null : [
                'is_relative' => $alarm->trigger->isRelative(),
                'is_absolute' => $alarm->trigger->isAbsolute(),
                'duration' => self::durationString($alarm->trigger->duration()),
                'date_time' => $alarm->trigger->dateTime()?->toIso8601String(),
                'related_to' => $alarm->trigger->relatedTo(),
            ],
            'description' => $alarm->description,
            'summary' => $alarm->summary,
            'attendees' => \array_values($alarm->attendees->map(fn (Attendee $attendee): array => $this->attendeeArray($attendee))->all()),
            'repeat' => $alarm->repeat,
            'duration' => self::durationString($alarm->duration),
        ];
    }

    /** Convert a date interval to a stable ISO 8601 duration string. */
    private static function durationString(?DateInterval $duration): ?string
    {
        if ($duration === null) {
            return null;
        }

        $date = ($duration->y ? $duration->y . 'Y' : '')
            . ($duration->m ? $duration->m . 'M' : '')
            . ($duration->d ? $duration->d . 'D' : '');
        $time = ($duration->h ? $duration->h . 'H' : '')
            . ($duration->i ? $duration->i . 'M' : '')
            . ($duration->s ? $duration->s . 'S' : '');

        if ($date === '' && $time === '') {
            $date = '0D';
        }

        return ($duration->invert ? '-' : '') . 'P' . $date . ($time === '' ? '' : 'T' . $time);
    }

    /**
     * Convert a generic component tree without collapsing ordered data.
     *
     * @return array{name: string, properties: list<array<string, mixed>>, components: list<array<string, mixed>>}
     */
    private function componentArray(Component $component): array
    {
        return [
            'name' => $component->name,
            'properties' => \array_values($component->properties()
                ->map(fn (Property $property): array => $this->propertyArray($property))
                ->all()),
            'components' => \array_values($component->components()
                ->map(fn (Component $child): array => $this->componentArray($child))
                ->all()),
        ];
    }

    /**
     * Convert a property without collapsing its values or parameters.
     *
     * @return PropertyArray
     */
    private function propertyArray(Property $property): array
    {
        return [
            'name' => $property->name,
            'type' => $property->type,
            'value' => $property->value,
            'values' => $property->values,
            'parameters' => $this->parameterArray($property->parameters()),
            'raw_value' => $property->rawValue(),
        ];
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

    /**
     * Normalize parameter list keys for the fixed serialization contract.
     *
     * @param  array<string, string|list<string>>  $parameters
     * @return array<string, string|list<string>>
     */
    private function parameterArray(array $parameters): array
    {
        return $parameters;
    }
}
