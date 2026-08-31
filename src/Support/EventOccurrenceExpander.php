<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Exceptions\RecurrenceLimitExceeded;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
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

/** Expand VEVENT recurrence sets within one bounded query. */
final class EventOccurrenceExpander
{
    private const int MAX_CANDIDATES = 3500;

    /**
     * Return concrete and generated events overlapping a half-open interval.
     *
     * @param  Closure(VEvent): Event  $eventHydrator
     * @return Collection<int, Event>
     *
     * @throws RecurrenceLimitExceeded
     * @throws UnsupportedRecurrence
     */
    public function expand(
        VCalendar $calendar,
        Closure $eventHydrator,
        string $floatingTimezone,
        int $fromTimestamp,
        int $untilTimestamp,
    ): Collection {
        $timezone = new DateTimeZone($floatingTimezone);
        $series = [];

        foreach ($calendar->select('VEVENT') as $ordinal => $component) {
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
                        eventHydrator: $eventHydrator,
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
                eventHydrator: $eventHydrator,
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
                                eventHydrator: $eventHydrator,
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

    /** Determine whether a VEVENT source explicitly cancels its occurrence. */
    private function isCancelled(VEvent $component): bool
    {
        return \strtoupper((string) $this->rawProperty($component, PropertyName::STATUS)) === 'CANCELLED';
    }

    /**
     * Reject recurrence forms that Sabre/VObject cannot represent safely.
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
     * Determine whether a UID group needs recurrence expansion.
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

    /** Count one logical candidate and enforce the per-query work bound. */
    private function countCandidate(int &$candidateCount): void
    {
        if (++$candidateCount > self::MAX_CANDIDATES) {
            throw new RecurrenceLimitExceeded(
                'The recurrence query exceeds its 3500-candidate limit. Narrow the date range.',
            );
        }
    }

    /** Count recurrence work skipped by an iterator fast-forward. */
    private function countCandidates(int &$candidateCount, int $amount): void
    {
        $candidateCount += $amount;

        if ($candidateCount > self::MAX_CANDIDATES) {
            throw new RecurrenceLimitExceeded(
                'The recurrence query exceeds its 3500-candidate limit. Narrow the date range.',
            );
        }
    }

    /**
     * Build one Sabre iterator source per inclusion type.
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
     * Clone a series and retain one master inclusion source.
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
     * Add explicit PERIOD RDATE inclusions to the shared recurrence set.
     *
     * @param  list<array{component: VEvent, ordinal: int}>  $events
     * @param  array{component: VEvent, ordinal: int}  $master
     * @param  Closure(VEvent): Event  $eventHydrator
     * @param  array<string, true>  $exclusions
     * @param  array<string, true>  $seen
     * @param  list<array{event: Event, masterOrdinal: int, sequence: int}>  $occurrences
     */
    private function appendPeriodOccurrences(
        array $events,
        array $master,
        Closure $eventHydrator,
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
                    eventHydrator: $eventHydrator,
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
     * @param  Closure(VEvent): Event  $eventHydrator
     * @param  list<array{event: Event, masterOrdinal: int, sequence: int}>  $occurrences
     */
    private function appendOccurrence(
        VEvent $component,
        Closure $eventHydrator,
        int $masterOrdinal,
        int $sequence,
        int $fromTimestamp,
        int $untilTimestamp,
        array &$occurrences,
    ): void {
        if ($this->isCancelled($component)) {
            return;
        }

        $event = $eventHydrator($component);

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

    /** Ensure every generated series instance retains its recurrence identifier. */
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

    /** Return a direct Sabre property without exposing magic access to analysis. */
    private function rawProperty(VEvent $component, string $name): ?SabreProperty
    {
        $property = $component->select($name)[0] ?? null;

        return $property instanceof SabreProperty ? $property : null;
    }
}
