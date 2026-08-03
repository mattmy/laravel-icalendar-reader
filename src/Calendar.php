<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonSerializable;
use Sabre\VObject\Component\VCalendar;

final readonly class Calendar implements JsonSerializable
{
    /**
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
     * Return events in document order.
     *
     * @return Collection<int, Event>
     */
    public function events(): Collection
    {
        return collect($this->eventItems);
    }

    /**
     * Determine whether any event, or an exact summary match, exists.
     */
    public function hasEvents(?string $name = null): bool
    {
        if ($name === null) {
            return $this->eventItems !== [];
        }

        foreach ($this->eventItems as $event) {
            if ($event->summary === $name) {
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

    /** @return Collection<int, Property> */
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

    public function hasProperty(?string $name = null): bool
    {
        return $this->properties($name)->isNotEmpty();
    }

    public function property(string $name): ?Property
    {
        return $this->properties($name)->first();
    }

    /** @return Collection<int, Component> */
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
     * Return a deep clone of the underlying low-level calendar component.
     */
    public function rawComponent(): VCalendar
    {
        return clone $this->component;
    }

    /** @return array{name: string, properties: list<mixed>, components: list<mixed>} */
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
     * @return array<string, mixed>
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
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Encode the domain-oriented representation as JSON. */
    public function toJson(int $options = 0): string
    {
        return \json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Convert one event for the Calendar output contract.
     *
     * @return array<string, mixed>
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
            'is_all_day' => $event->isAllDay(),
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
            'attendees' => $event->attendees->map(fn (Attendee $attendee): array => $this->attendeeArray($attendee))->values()->all(),
            'alarms' => $event->alarms->map(fn (Alarm $alarm): array => $this->alarmArray($alarm))->values()->all(),
            'categories' => $event->categories->values()->all(),
        ];
    }

    /**
     * Convert an organizer for the Calendar output contract.
     *
     * @return array<string, mixed>
     */
    private function organizerArray(Organizer $organizer): array
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
     * Convert an attendee for the Calendar output contract.
     *
     * @return array<string, mixed>
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
            'delegated_from' => $attendee->delegatedFrom->values()->all(),
            'delegated_to' => $attendee->delegatedTo->values()->all(),
            'parameters' => $attendee->parameters(),
        ];
    }

    /**
     * Convert an alarm for the Calendar output contract.
     *
     * @return array<string, mixed>
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
            'attendees' => $alarm->attendees->map(fn (Attendee $attendee): array => $this->attendeeArray($attendee))->values()->all(),
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
     * @return array{name: string, type: string, value: mixed, values: list<mixed>, parameters: array<string, string|list<string>>, raw_value: string}
     */
    private function propertyArray(Property $property): array
    {
        return [
            'name' => $property->name,
            'type' => $property->type,
            'value' => $property->value,
            'values' => $property->values,
            'parameters' => $property->parameters(),
            'raw_value' => $property->rawValue(),
        ];
    }

    private function normalizeName(string $name, string $kind): string
    {
        $name = \trim($name);

        if ($name === '') {
            throw new InvalidArgumentException("{$kind} names must not be empty.");
        }

        return \strtoupper($name);
    }
}
