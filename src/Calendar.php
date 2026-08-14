<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Mattmy\ICalendar\Concerns\QueriesProperties;
use Mattmy\ICalendar\Exceptions\RecurrenceLimitExceeded;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
use Mattmy\ICalendar\Support\CalendarSerializer;
use Mattmy\ICalendar\Support\PropertyName;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property as SabreProperty;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Property\ICalendar\Period as PeriodProperty;
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

    private const int MAX_RECURRENCE_CANDIDATES = 3500;

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
            $exclusions = $this->recurrenceExclusions($master['component'], $timezone);

            $this->appendPeriodOccurrences(
                events: $events,
                master: $master,
                timezone: $timezone,
                exclusions: $exclusions,
                seen: $seen,
                candidateCount: $candidateCount,
                sequence: $sequence,
                fromTimestamp: $fromTimestamp,
                untilTimestamp: $untilTimestamp,
                occurrences: $occurrences,
            );

            foreach ($this->recurrenceSources($events, $master) as $source) {
                try {
                    $iterator = new EventIterator($source, null, $timezone);
                    $iterator->fastForward((new DateTimeImmutable('@' . $fromTimestamp))->setTimezone($timezone));
                    $this->countCandidates($candidateCount, $iterator->key());

                    while ($iterator->valid()) {
                        $start = $iterator->getDtStart();

                        if ($start === null || $start->getTimestamp() >= $untilTimestamp) {
                            break;
                        }

                        $this->countCandidate($candidateCount);
                        $component = clone $iterator->getEventObject();
                        $this->ensureRecurrenceId($component);
                        $key = $this->recurrenceKey($component, $timezone);

                        if (! isset($exclusions[$key]) && ! isset($seen[$key])) {
                            $seen[$key] = true;
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
                } catch (MaxInstancesExceededException $exception) {
                    throw new RecurrenceLimitExceeded(
                        'The recurrence query exceeds its 3500-candidate limit. Narrow the date range.',
                        previous: $exception,
                    );
                } catch (InvalidDataException $exception) {
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

    /** Count recurrence work skipped by an iterator fast-forward. */
    private function countCandidates(int &$candidateCount, int $amount): void
    {
        $candidateCount += $amount;

        if ($candidateCount > self::MAX_RECURRENCE_CANDIDATES) {
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
        $hasDates = $this->hasDateRDates($master['component']);
        $sources = [];

        if ($hasRule) {
            $sources[] = $this->recurrenceSource($events, keepRule: true, keepDates: false);
        }

        if ($hasDates) {
            $sources[] = $this->recurrenceSource($events, keepRule: false, keepDates: true);
        }

        return $sources === []
            ? [$this->recurrenceSource($events, keepRule: false, keepDates: false)]
            : $sources;
    }

    /**
     * Clone a series and remove one master inclusion property for a Sabre iterator.
     *
     * @param  list<array{component: VEvent, ordinal: int}>  $events
     * @return list<VEvent>
     */
    private function recurrenceSource(array $events, bool $keepRule, bool $keepDates): array
    {
        $source = [];

        foreach ($events as $event) {
            $component = clone $event['component'];
            unset($component->{PropertyName::EXDATE});

            if (! isset($component->{PropertyName::RECURRENCE_ID})) {
                if (! $keepRule) {
                    unset($component->{PropertyName::RRULE});
                }

                foreach ($component->select(PropertyName::RDATE) as $property) {
                    if (! $keepDates || $property instanceof PeriodProperty) {
                        $component->remove($property);
                    }
                }
            }

            $source[] = $component;
        }

        return $source;
    }

    /** Determine whether a master has DATE or DATE-TIME RDATE inclusions. */
    private function hasDateRDates(VEvent $master): bool
    {
        foreach ($master->select(PropertyName::RDATE) as $property) {
            if ($property instanceof DateTimeProperty) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function recurrenceExclusions(VEvent $master, DateTimeZone $timezone): array
    {
        $exclusions = [];

        foreach ($master->select(PropertyName::EXDATE) as $property) {
            if (! $property instanceof DateTimeProperty) {
                continue;
            }

            foreach ($property->getDateTimes($timezone) as $dateTime) {
                if ($dateTime instanceof DateTimeInterface) {
                    $exclusions['T:' . $dateTime->format('U.u')] = true;
                }
            }
        }

        return $exclusions;
    }

    /**
     * Add explicit PERIOD RDATE inclusions to the shared recurrence pipeline.
     *
     * @param  list<array{component: VEvent, ordinal: int}>  $events
     * @param  array{component: VEvent, ordinal: int}  $master
     * @param  array<string, true>  $exclusions
     * @param  array<string, true>  $seen
     * @param  list<array{event: Event, masterOrdinal: int, sequence: int}>  $occurrences
     */
    private function appendPeriodOccurrences(
        array $events,
        array $master,
        DateTimeZone $timezone,
        array $exclusions,
        array &$seen,
        int &$candidateCount,
        int &$sequence,
        int $fromTimestamp,
        int $untilTimestamp,
        array &$occurrences,
    ): void {
        $overrides = [];

        foreach ($events as $event) {
            if ($this->rawProperty($event['component'], PropertyName::RECURRENCE_ID) !== null) {
                $overrides[$this->recurrenceKey($event['component'], $timezone)] = $event['component'];
            }
        }

        foreach ($master['component']->select(PropertyName::RDATE) as $property) {
            if (! $property instanceof PeriodProperty) {
                continue;
            }

            foreach ($property->getParts() as $period) {
                $this->countCandidate($candidateCount);
                [$start, $end] = \explode('/', (string) $period, 2);
                $component = clone $master['component'];
                $this->removeRecurrenceGenerators($component);
                $this->setDateTimeProperty($component, PropertyName::DTSTART, $start, $property);
                unset($component->{PropertyName::DTEND}, $component->{PropertyName::DURATION}, $component->{PropertyName::RECURRENCE_ID});

                if (\str_starts_with($end, 'P') || \str_starts_with($end, '+P')) {
                    $component->add(PropertyName::DURATION, $end);
                } else {
                    $this->setDateTimeProperty($component, PropertyName::DTEND, $end, $property);
                }

                $this->ensureRecurrenceId($component);
                $key = $this->recurrenceKey($component, $timezone);

                if (isset($exclusions[$key]) || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $effective = isset($overrides[$key]) ? clone $overrides[$key] : $component;
                $this->removeRecurrenceGenerators($effective);
                $this->appendOccurrence(
                    component: $effective,
                    masterOrdinal: $master['ordinal'],
                    sequence: $sequence++,
                    fromTimestamp: $fromTimestamp,
                    untilTimestamp: $untilTimestamp,
                    occurrences: $occurrences,
                );
            }
        }
    }

    /** Copy one PERIOD endpoint into a concrete VEVENT date-time property. */
    private function setDateTimeProperty(VEvent $component, string $name, string $value, PeriodProperty $period): void
    {
        $component->remove($name);
        $tzid = $period['TZID'];
        $component->add($name, $value, $tzid instanceof Parameter ? ['TZID' => (string) $tzid] : []);
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
            return 'T:' . ($recurrenceId->getDateTime($timezone)?->format('U.u') ?? (string) $recurrenceId);
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
