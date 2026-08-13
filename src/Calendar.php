<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use Closure;
use DateInterval;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Mattmy\ICalendar\Concerns\QueriesProperties;
use Mattmy\ICalendar\Exceptions\RecurrenceLimitExceeded;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
use Mattmy\ICalendar\Support\PropertyName;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property as SabreProperty;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\NoInstancesException;

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
 * @phpstan-type AlarmArray array{action: ?string, trigger: ?AlarmTriggerArray, description: ?string, summary: ?string, attendees: list<AttendeeArray>, repeat: ?int, duration: ?string}
 * @phpstan-type GeoArray array{latitude: float, longitude: float}
 * @phpstan-type EventArray array{uid: ?string, summary: ?string, description: ?string, location: ?string, starts_at: ?string, ends_at: ?string, start_is_date: bool, end_is_date: bool, start_is_floating: bool, end_is_floating: bool, is_all_day: bool, last_day: ?string, duration: ?string, timestamp: ?string, created_at: ?string, last_modified_at: ?string, status: ?string, classification: ?string, priority: ?int, recurrence_id: ?string, recurrence_id_is_date: bool, recurrence_id_is_floating: bool, sequence: ?int, url: ?string, organizer: ?OrganizerArray, attendees: list<AttendeeArray>, alarms: list<AlarmArray>, categories: list<string>, geo: ?GeoArray, transparency: ?string, comments: list<string>, contacts: list<string>, resources: list<string>, recurrence_rule: ?PropertyArray, attachments: list<PropertyArray>, exception_dates: list<PropertyArray>, request_statuses: list<PropertyArray>, related_to: list<PropertyArray>, recurrence_dates: list<PropertyArray>}
 * @phpstan-type TodoArray array{uid: ?string, timestamp: ?string, classification: ?string, completed_at: ?string, created_at: ?string, description: ?string, starts_at: ?string, start_is_date: bool, start_is_floating: bool, due_at: ?string, due_is_date: bool, due_is_floating: bool, duration: ?string, last_modified_at: ?string, location: ?string, organizer: ?OrganizerArray, percent_complete: ?int, priority: ?int, recurrence_id: ?string, recurrence_id_is_date: bool, recurrence_id_is_floating: bool, sequence: ?int, status: ?string, summary: ?string, url: ?string, attendees: list<AttendeeArray>, categories: list<string>, alarms: list<AlarmArray>, geo: ?GeoArray, comments: list<string>, contacts: list<string>, resources: list<string>, recurrence_rule: ?PropertyArray, attachments: list<PropertyArray>, exception_dates: list<PropertyArray>, request_statuses: list<PropertyArray>, related_to: list<PropertyArray>, recurrence_dates: list<PropertyArray>}
 * @phpstan-type CalendarArray array{version: ?string, product_id: ?string, method: ?string, calendar_scale: ?string, floating_timezone: string, events: list<EventArray>, todos: list<TodoArray>, warnings: list<IssueArray>}
 */
final readonly class Calendar implements JsonSerializable
{
    use QueriesProperties;

    private const int MAX_RECURRENCE_CANDIDATES = 3500;

    /**
     * Hydrate an immutable calendar snapshot and its ordered child data.
     *
     * @param  list<Event>  $eventItems
     * @param  list<Todo>  $todoItems
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
        private array $warningItems,
        private array $propertyItems,
        private array $componentItems,
        private VCalendar $component,
        private Closure $eventHydrator,
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

        $timezone = new DateTimeZone($this->floatingTimezone);
        $series = [];

        foreach ($this->component->select('VEVENT') as $ordinal => $component) {
            if ($component instanceof VEvent) {
                $series[(string) $this->rawProperty($component, PropertyName::UID)][] = [
                    'component' => $component,
                    'ordinal' => $ordinal,
                ];
            }
        }

        $candidateCount = 0;
        $occurrences = [];

        foreach ($series as $events) {
            if (! $this->isRecurrenceSeries($events)) {
                foreach ($events as $event) {
                    $this->countCandidate($candidateCount);
                    $this->appendOccurrence(
                        component: clone $event['component'],
                        masterOrdinal: $event['ordinal'],
                        sequence: 0,
                        fromTimestamp: $fromTimestamp,
                        untilTimestamp: $untilTimestamp,
                        occurrences: $occurrences,
                    );
                }

                continue;
            }

            $master = $this->assertSupportedSeries($events);

            if ($this->isCancelled($master['component'])) {
                continue;
            }

            $seen = [];
            $sequence = 0;

            foreach ($this->recurrenceSources($events, $master) as $source) {
                try {
                    $iterator = new EventIterator($source, null, $timezone);

                    while ($iterator->valid()) {
                        $component = clone $iterator->getEventObject();
                        $this->ensureRecurrenceId($component);
                        $key = $this->recurrenceKey($component, $timezone);

                        if (! isset($seen[$key])) {
                            $seen[$key] = true;
                            $this->countCandidate($candidateCount);
                            $this->removeRecurrenceGenerators($component);
                            $this->appendOccurrence(
                                component: $component,
                                masterOrdinal: $master['ordinal'],
                                sequence: $sequence++,
                                fromTimestamp: $fromTimestamp,
                                untilTimestamp: $untilTimestamp,
                                occurrences: $occurrences,
                            );
                        }

                        $iterator->next();
                    }
                } catch (RecurrenceLimitExceeded $exception) {
                    throw $exception;
                } catch (NoInstancesException) {
                    continue;
                } catch (MaxInstancesExceededException|InvalidDataException $exception) {
                    throw new UnsupportedRecurrence(
                        'The recurrence for UID ' . ((string) $this->rawProperty($master['component'], PropertyName::UID)) . ' cannot be expanded safely.',
                        $exception,
                    );
                }
            }
        }

        \usort($occurrences, static function (array $left, array $right): int {
            $start = $left['event']->startsAt?->getTimestamp() <=> $right['event']->startsAt?->getTimestamp();

            return $start !== 0
                ? $start
                : [$left['masterOrdinal'], $left['sequence']] <=> [$right['masterOrdinal'], $right['sequence']];
        });

        return collect(\array_column($occurrences, 'event'));
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
     * @return ComponentArray
     */
    public function toComponentArray(): array
    {
        return [
            'name' => 'VCALENDAR',
            'properties' => \array_map(
                fn (Property $property): array => $property->toArray(),
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
            'todos' => \array_map(
                fn (Todo $todo): array => $this->todoArray($todo),
                $this->todoItems,
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

    /** Determine whether a VEVENT source explicitly cancels its occurrence. */
    private function isCancelled(VEvent $component): bool
    {
        return \strtoupper((string) $this->rawProperty($component, PropertyName::STATUS)) === 'CANCELLED';
    }

    /**
     * Reject recurrence forms that Sabre/VObject 5 cannot represent safely.
     *
     * @param  list<array{component: VEvent, ordinal: int}>  $events
     * @return array{component: VEvent, ordinal: int}
     *
     * @throws UnsupportedRecurrence
     */
    private function assertSupportedSeries(array $events): array
    {
        $masters = \array_values(\array_filter(
            $events,
            static fn (array $event): bool => ! isset($event['component']->{PropertyName::RECURRENCE_ID}),
        ));

        if (\count($masters) !== 1) {
            throw new UnsupportedRecurrence('A recurrence series must contain exactly one master event.');
        }

        $master = $masters[0];

        if (\count($master['component']->select(PropertyName::RRULE)) > 1) {
            throw new UnsupportedRecurrence('Multiple RRULE properties cannot be expanded safely.');
        }

        foreach ($events as $event) {
            $recurrenceId = $this->rawProperty($event['component'], PropertyName::RECURRENCE_ID);
            $range = $recurrenceId?->offsetGet('RANGE');

            if ($range instanceof Parameter && \strtoupper((string) $range) === 'THISANDFUTURE') {
                throw new UnsupportedRecurrence('RECURRENCE-ID;RANGE=THISANDFUTURE is not supported.');
            }
        }

        return $master;
    }

    /**
     * Determine whether a UID group needs recurrence expansion rather than direct event filtering.
     *
     * @param  list<array{component: VEvent, ordinal: int}>  $events
     */
    private function isRecurrenceSeries(array $events): bool
    {
        foreach ($events as $event) {
            foreach ([PropertyName::RRULE, PropertyName::RDATE, PropertyName::EXDATE, PropertyName::RECURRENCE_ID] as $name) {
                if ($this->rawProperty($event['component'], $name) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Count one logical occurrence candidate and enforce the per-query upper bound.
     *
     * @throws RecurrenceLimitExceeded
     */
    private function countCandidate(int &$candidateCount): void
    {
        if (++$candidateCount > self::MAX_RECURRENCE_CANDIDATES) {
            throw new RecurrenceLimitExceeded(
                'The recurrence query exceeds its 3500-candidate limit. Narrow the date range.',
            );
        }
    }

    /**
     * Build one Sabre iterator source per inclusion type so RRULE and RDATE form a union.
     *
     * @param  list<array{component: VEvent, ordinal: int}>  $events
     * @param  array{component: VEvent, ordinal: int}  $master
     * @return list<list<VEvent>>
     */
    private function recurrenceSources(array $events, array $master): array
    {
        $hasRule = isset($master['component']->{PropertyName::RRULE});
        $hasDates = isset($master['component']->{PropertyName::RDATE});

        if (! $hasRule || ! $hasDates) {
            return [[...\array_column($events, 'component')]];
        }

        return [
            $this->recurrenceSource($events, remove: PropertyName::RDATE),
            $this->recurrenceSource($events, remove: PropertyName::RRULE),
        ];
    }

    /**
     * Clone a series and remove one master inclusion property for a Sabre iterator.
     *
     * @param  list<array{component: VEvent, ordinal: int}>  $events
     * @return list<VEvent>
     */
    private function recurrenceSource(array $events, string $remove): array
    {
        $source = [];

        foreach ($events as $event) {
            $component = clone $event['component'];

            if (! isset($component->{PropertyName::RECURRENCE_ID})) {
                unset($component->{$remove});
            }

            $source[] = $component;
        }

        return $source;
    }

    /**
     * Add one effective VEVENT when it is active and overlaps the requested range.
     *
     * @param  list<array{event: Event, masterOrdinal: int, sequence: int}>  $occurrences
     */
    private function appendOccurrence(
        VEvent $component,
        int $masterOrdinal,
        int $sequence,
        int $fromTimestamp,
        int $untilTimestamp,
        array &$occurrences,
    ): void {
        if ($this->isCancelled($component)) {
            return;
        }

        /** @var Event $event */
        $event = ($this->eventHydrator)($component);

        if ($event->startsAt === null) {
            return;
        }

        $start = $event->startsAt->getTimestamp();

        if ($event->endsAt === null) {
            if ($fromTimestamp > $start || $start >= $untilTimestamp) {
                return;
            }
        } elseif ($start >= $untilTimestamp || $event->endsAt->getTimestamp() <= $fromTimestamp) {
            return;
        }

        $occurrences[] = [
            'event' => $event,
            'masterOrdinal' => $masterOrdinal,
            'sequence' => $sequence,
        ];
    }

    /** Return a stable key for an effective recurrence instance. */
    private function recurrenceKey(VEvent $component, DateTimeZone $timezone): string
    {
        $recurrenceId = $this->rawProperty($component, PropertyName::RECURRENCE_ID);

        if (! $recurrenceId instanceof DateTimeProperty) {
            return (string) $this->rawProperty($component, PropertyName::DTSTART);
        }

        try {
            return $recurrenceId->getDateTime($timezone)?->format('U.u') ?? (string) $recurrenceId;
        } catch (InvalidDataException $exception) {
            throw new UnsupportedRecurrence('A RECURRENCE-ID cannot be resolved safely.', $exception);
        }
    }

    /** Remove recurrence generators from a concrete generated occurrence. */
    private function removeRecurrenceGenerators(VEvent $component): void
    {
        unset(
            $component->{PropertyName::RRULE},
            $component->{PropertyName::RDATE},
            $component->{PropertyName::EXDATE},
        );
    }

    /** Ensure every generated series instance retains its original recurrence identifier. */
    private function ensureRecurrenceId(VEvent $component): void
    {
        if ($this->rawProperty($component, PropertyName::RECURRENCE_ID) !== null
            || $this->rawProperty($component, PropertyName::DTSTART) === null) {
            return;
        }

        $recurrenceId = clone $this->rawProperty($component, PropertyName::DTSTART);
        $recurrenceId->name = PropertyName::RECURRENCE_ID;
        $component->add($recurrenceId);
    }

    /** Return a direct Sabre property without exposing magic component access to static analysis. */
    private function rawProperty(VEvent $component, string $name): ?SabreProperty
    {
        $property = $component->select($name)[0] ?? null;

        return $property instanceof SabreProperty ? $property : null;
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
            'starts_at' => self::dateTimeString($event->startsAt, $event->startIsDate),
            'ends_at' => self::dateTimeString($event->endsAt, $event->endIsDate),
            'start_is_date' => $event->startIsDate,
            'end_is_date' => $event->endIsDate,
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
            'recurrence_id' => self::dateTimeString($event->recurrenceId, $event->recurrenceIdIsDate),
            'recurrence_id_is_date' => $event->recurrenceIdIsDate,
            'recurrence_id_is_floating' => $event->recurrenceIdIsFloating,
            'sequence' => $event->sequence,
            'url' => $event->url,
            'organizer' => $event->organizer === null ? null : $this->organizerArray($event->organizer),
            'attendees' => \array_values($event->attendees->map(fn (Attendee $attendee): array => $this->attendeeArray($attendee))->all()),
            'alarms' => \array_values($event->alarms->map(fn (Alarm $alarm): array => $this->alarmArray($alarm))->all()),
            'categories' => \array_values($event->categories->all()),
            'geo' => $event->geo,
            'transparency' => $event->transparency,
            'comments' => \array_values($event->comments->all()),
            'contacts' => \array_values($event->contacts->all()),
            'resources' => \array_values($event->resources->all()),
            'recurrence_rule' => $event->recurrenceRule?->toArray(),
            'attachments' => $this->propertyArrays($event->attachments),
            'exception_dates' => $this->propertyArrays($event->exceptionDates),
            'request_statuses' => $this->propertyArrays($event->requestStatuses),
            'related_to' => $this->propertyArrays($event->relatedTo),
            'recurrence_dates' => $this->propertyArrays($event->recurrenceDates),
        ];
    }

    /**
     * Convert one todo for the Calendar output contract.
     *
     * @return TodoArray
     */
    private function todoArray(Todo $todo): array
    {
        return [
            'uid' => $todo->uid,
            'timestamp' => $todo->timestamp?->toIso8601String(),
            'classification' => $todo->classification,
            'completed_at' => $todo->completedAt?->toIso8601String(),
            'created_at' => $todo->createdAt?->toIso8601String(),
            'description' => $todo->description,
            'starts_at' => self::dateTimeString($todo->startsAt, $todo->startIsDate),
            'start_is_date' => $todo->startIsDate,
            'start_is_floating' => $todo->startIsFloating,
            'due_at' => self::dateTimeString($todo->dueAt, $todo->dueIsDate),
            'due_is_date' => $todo->dueIsDate,
            'due_is_floating' => $todo->dueIsFloating,
            'duration' => self::durationString($todo->duration),
            'last_modified_at' => $todo->lastModifiedAt?->toIso8601String(),
            'location' => $todo->location,
            'organizer' => $todo->organizer === null ? null : $this->organizerArray($todo->organizer),
            'percent_complete' => $todo->percentComplete,
            'priority' => $todo->priority,
            'recurrence_id' => self::dateTimeString($todo->recurrenceId, $todo->recurrenceIdIsDate),
            'recurrence_id_is_date' => $todo->recurrenceIdIsDate,
            'recurrence_id_is_floating' => $todo->recurrenceIdIsFloating,
            'sequence' => $todo->sequence,
            'status' => $todo->status,
            'summary' => $todo->summary,
            'url' => $todo->url,
            'attendees' => \array_values($todo->attendees->map(fn (Attendee $attendee): array => $this->attendeeArray($attendee))->all()),
            'categories' => \array_values($todo->categories->all()),
            'alarms' => \array_values($todo->alarms->map(fn (Alarm $alarm): array => $this->alarmArray($alarm))->all()),
            'geo' => $todo->geo,
            'comments' => \array_values($todo->comments->all()),
            'contacts' => \array_values($todo->contacts->all()),
            'resources' => \array_values($todo->resources->all()),
            'recurrence_rule' => $todo->recurrenceRule?->toArray(),
            'attachments' => $this->propertyArrays($todo->attachments),
            'exception_dates' => $this->propertyArrays($todo->exceptionDates),
            'request_statuses' => $this->propertyArrays($todo->requestStatuses),
            'related_to' => $this->propertyArrays($todo->relatedTo),
            'recurrence_dates' => $this->propertyArrays($todo->recurrenceDates),
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

    /** Format a date-only or date-time value for the public output contract. */
    private static function dateTimeString(?CarbonImmutable $value, bool $isDate): ?string
    {
        if ($value === null) {
            return null;
        }

        return $isDate ? $value->toDateString() : $value->toIso8601String();
    }

    /**
     * Convert a generic component tree without collapsing ordered data.
     *
     * @return ComponentArray
     */
    private function componentArray(Component $component): array
    {
        return [
            'name' => $component->name,
            'properties' => \array_values($component->properties()
                ->map(fn (Property $property): array => $property->toArray())
                ->all()),
            'components' => \array_values($component->components()
                ->map(fn (Component $child): array => $this->componentArray($child))
                ->all()),
        ];
    }

    /**
     * Convert ordered generic property shortcuts for the Calendar output contract.
     *
     * @param  Collection<int, Property>  $properties
     * @return list<PropertyArray>
     */
    private function propertyArrays(Collection $properties): array
    {
        return \array_values($properties->map(static fn (Property $property): array => $property->toArray())->all());
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
