<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Closure;
use DateTimeZone;
use Illuminate\Support\Collection;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Exceptions\RecurrenceLimitExceeded;
use Mattmy\ICalendar\Exceptions\UnresolvableEventRange;
use Mattmy\ICalendar\Exceptions\UnsupportedRecurrence;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
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
    private const MAX_CANDIDATES = 3500;

    /**
     * Return concrete and generated events overlapping a half-open interval.
     *
     * @param  Closure(VEvent): Event  $eventHydrator
     * @return Collection<int, Event>
     *
     * @throws RecurrenceLimitExceeded
     * @throws UnresolvableEventRange
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
            $startProperty = $this->rawProperty($master['component'], PropertyName::DTSTART);
            $localTimezone = $startProperty?->offsetGet(ParameterName::TZID);
            $masterEvent = $eventHydrator($master['component']);

            if ($masterEvent->startsAt === null
                || ($this->requiresEnd($masterEvent) && $masterEvent->endsAt === null)) {
                throw new UnsupportedRecurrence('A recurrence master date cannot be resolved safely.');
            }

            $until = $this->rawProperty($master['component'], PropertyName::RRULE)?->getParts()['UNTIL'] ?? null;
            $ruleUntil = $localTimezone instanceof Parameter && \is_string($until)
                ? DateTimeParser::parseDateTime($until)->getTimestamp()
                : null;

            foreach ($events as $event) {
                if ($this->rawProperty($event['component'], PropertyName::RECURRENCE_ID) === null) {
                    continue;
                }

                $this->countCandidate($candidateCount);
                $key = $this->recurrenceKey($event['component'], $timezone);
                $seen[$key] = true;

                if (! isset($exclusions[$key])) {
                    $component = clone $event['component'];
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
            }

            $this->appendRDateOccurrences(
                master: $master,
                masterEvent: $masterEvent,
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

            try {
                $iterator = new EventIterator([$this->recurrenceSource($master['component'])], null, $localTimezone instanceof Parameter ? new DateTimeZone('UTC') : $timezone);

                while ($iterator->getDtStart() !== null) {
                    $start = $iterator->getDtStart();

                    if (! $localTimezone instanceof Parameter && $start->getTimestamp() >= $untilTimestamp) {
                        break;
                    }

                    if (! $localTimezone instanceof Parameter) {
                        $this->countCandidate($candidateCount);
                    }

                    $component = clone $iterator->getEventObject();

                    if ($localTimezone instanceof Parameter) {
                        foreach ([PropertyName::DTSTART, PropertyName::RECURRENCE_ID] as $name) {
                            $property = $this->rawProperty($component, $name);

                            if ($property !== null) {
                                $property->offsetUnset(ParameterName::TZID);
                                $property->add(ParameterName::TZID, (string) $localTimezone);
                            }
                        }

                        $mappedStart = (new DateTimeMapper())->value($this->rawProperty($component, PropertyName::DTSTART), $floatingTimezone);

                        if ($mappedStart === null) {
                            throw new UnsupportedRecurrence('A recurring DTSTART cannot be resolved safely.');
                        }

                        $start = $mappedStart;

                        if (isset($master['component']->{PropertyName::DTEND}) && $masterEvent->endsAt !== null) {
                            $end = $mappedStart->addSeconds($masterEvent->endsAt->getTimestamp() - $masterEvent->startsAt->getTimestamp());
                            $component->remove(PropertyName::DTEND);
                            $component->add(PropertyName::DTEND, $end->utc()->format('Ymd\THis\Z'));
                        }
                    }

                    if ($start->getTimestamp() >= $untilTimestamp || ($ruleUntil !== null && $start->getTimestamp() > $ruleUntil)) {
                        break;
                    }

                    if ($localTimezone instanceof Parameter) {
                        $this->countCandidate($candidateCount);
                    }
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

        $rule = $this->rawProperty($master['component'], PropertyName::RRULE)?->getParts() ?? [];

        if (\in_array(\strtoupper(ParserValue::text($rule['FREQ'] ?? '')), ['SECONDLY', 'MINUTELY'], true)
            || isset($rule['BYSECOND']) || isset($rule['BYMINUTE'])) {
            throw new UnsupportedRecurrence('This recurrence frequency or time expansion is not supported safely.');
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

    /**
     * Count one logical candidate and enforce the per-query work bound.
     *
     * @throws RecurrenceLimitExceeded
     */
    private function countCandidate(int &$candidateCount): void
    {
        if (++$candidateCount > self::MAX_CANDIDATES) {
            throw new RecurrenceLimitExceeded(
                'The recurrence query exceeds its 3500-candidate limit. Narrow the date range.',
            );
        }
    }

    /**
     * Clone the master rule source and neutralize calendar-defined local times.
     */
    private function recurrenceSource(VEvent $master): VEvent
    {
        $component = clone $master;
        unset($component->{PropertyName::EXDATE}, $component->{PropertyName::RDATE});
        $start = $this->rawProperty($component, PropertyName::DTSTART);
        $localTimezone = $start?->offsetGet(ParameterName::TZID);
        $rule = $this->rawProperty($component, PropertyName::RRULE);

        if ($rule !== null) {
            $parts = ParserValue::recurrenceParts($rule);

            if ($start instanceof DateTimeProperty && $start->getValueType() === 'DATE') {
                unset($parts['BYHOUR']);
            }

            if ($localTimezone instanceof Parameter) {
                unset($parts['UNTIL']);
            }

            $rule->setValue($parts);
        }

        if ($start !== null && $localTimezone instanceof Parameter) {
            unset($component->{PropertyName::DTEND});
            $start->offsetUnset(ParameterName::TZID);
        }

        return $component;
    }

    /**
     * Map every EXDATE into the same instant keys used by generated occurrences.
     *
     * @return array<string, true>
     *
     * @throws UnsupportedRecurrence
     */
    private function recurrenceExclusions(VEvent $master, DateTimeZone $timezone): array
    {
        $exclusions = [];

        foreach ($master->select(PropertyName::EXDATE) as $property) {
            if (! $property instanceof DateTimeProperty) {
                continue;
            }

            $dates = (new DateTimeMapper())->values($property, $timezone->getName())
                ?? throw new UnsupportedRecurrence('An EXDATE cannot be resolved safely.');

            foreach ($dates as $dateTime) {
                $exclusions['T:' . $dateTime->format('U.u')] = true;
            }
        }

        return $exclusions;
    }

    /**
     * Add explicit DATE, DATE-TIME, and PERIOD RDATE inclusions to the shared set.
     *
     * @param  array{component: VEvent, ordinal: int}  $master
     * @param  Closure(VEvent): Event  $eventHydrator
     * @param  array<string, true>  $exclusions
     * @param  array<string, true>  $seen
     * @param  list<array{event: Event, masterOrdinal: int, sequence: int}>  $occurrences
     *
     * @throws RecurrenceLimitExceeded
     * @throws UnsupportedRecurrence
     */
    private function appendRDateOccurrences(
        array $master,
        Event $masterEvent,
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
        foreach ($master['component']->select(PropertyName::RDATE) as $property) {
            if (! $property instanceof PeriodProperty && ! $property instanceof DateTimeProperty) {
                continue;
            }

            foreach ($property->getParts() as $period) {
                $this->countCandidate($candidateCount);
                $parts = \explode('/', ParserValue::text($period), 2);
                $start = $parts[0];
                $end = $parts[1] ?? null;
                $component = clone $master['component'];
                $this->removeRecurrenceGenerators($component);
                if ($property instanceof DateTimeProperty) {
                    $date = clone $property;
                    $date->name = PropertyName::DTSTART;
                    $date->setValue($start);
                    $component->remove(PropertyName::DTSTART);
                    $component->add($date);
                } else {
                    $this->setDateTimeProperty($component, PropertyName::DTSTART, $start, $property);
                }
                unset($component->{PropertyName::DTEND}, $component->{PropertyName::DURATION}, $component->{PropertyName::RECURRENCE_ID});

                if ($end === null) {
                    $duration = $this->rawProperty($master['component'], PropertyName::DURATION);

                    if ($duration !== null) {
                        $component->add(clone $duration);
                    } elseif ($masterEvent->startsAt !== null && $masterEvent->endsAt !== null && $property instanceof DateTimeProperty) {
                        $startProperty = $this->rawProperty($component, PropertyName::DTSTART);
                        $date = (new DateTimeMapper())->value($startProperty, $timezone->getName());

                        if ($date !== null && $startProperty instanceof DateTimeProperty) {
                            if ($property->getValueType() === 'DATE') {
                                $endDate = clone $startProperty;
                                $endDate->name = PropertyName::DTEND;
                                $endDate->setValue($date->add($masterEvent->startsAt->diff($masterEvent->endsAt))->format('Ymd'));
                                $component->add($endDate);
                            } else {
                                $endDate = $date->addSeconds($masterEvent->endsAt->getTimestamp() - $masterEvent->startsAt->getTimestamp());
                                $component->add(PropertyName::DTEND, $masterEvent->endIsFloating
                                    ? $endDate->format('Ymd\THis')
                                    : $endDate->utc()->format('Ymd\THis\Z'));
                            }
                        }
                    }
                } elseif (\str_starts_with($end, 'P') || \str_starts_with($end, '+P')) {
                    $component->add(PropertyName::DURATION, $end);
                } elseif ($property instanceof PeriodProperty) {
                    $this->setDateTimeProperty($component, PropertyName::DTEND, $end, $property);
                }

                $this->ensureRecurrenceId($component);
                $key = $this->recurrenceKey($component, $timezone);

                if (isset($exclusions[$key]) || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
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
        }
    }

    /** Copy one PERIOD endpoint into a concrete VEVENT date-time property. */
    private function setDateTimeProperty(VEvent $component, string $name, string $value, PeriodProperty $period): void
    {
        $component->remove($name);
        $tzid = $period['TZID'];
        $property = $component->add($name, $value, $tzid instanceof Parameter ? ['TZID' => (string) $tzid] : []);
        $property->parent = $component;
    }

    /**
     * Add one effective VEVENT when it is active and overlaps the requested range.
     *
     * @param  Closure(VEvent): Event  $eventHydrator
     * @param  list<array{event: Event, masterOrdinal: int, sequence: int}>  $occurrences
     *
     * @throws UnresolvableEventRange
     * @throws UnsupportedRecurrence
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

        if ($this->rawProperty($component, PropertyName::RECURRENCE_ID) !== null
            && ($event->startsAt === null || ($this->requiresEnd($event) && $event->endsAt === null))) {
            throw new UnsupportedRecurrence('A recurrence occurrence date cannot be resolved safely.');
        }

        if ($event->startsAt === null || $event->startsAt->getTimestamp() >= $untilTimestamp) {
            return;
        }

        $start = $event->startsAt->getTimestamp();

        if ($event->endsAt === null) {
            if ($this->requiresEnd($event)) {
                throw new UnresolvableEventRange('An event endpoint required by the range query cannot be resolved safely.');
            }

            if ($fromTimestamp > $start) {
                return;
            }
        } elseif ($event->endsAt->getTimestamp() <= $fromTimestamp) {
            return;
        }

        $occurrences[] = [
            'event' => $event,
            'masterOrdinal' => $masterOrdinal,
            'sequence' => $sequence,
        ];
    }

    /**
     * Return a resolved instant key for an effective recurrence instance.
     *
     * @throws UnsupportedRecurrence
     */
    private function recurrenceKey(VEvent $component, DateTimeZone $timezone): string
    {
        $recurrenceId = $this->rawProperty($component, PropertyName::RECURRENCE_ID);

        if (! $recurrenceId instanceof DateTimeProperty) {
            return (string) $this->rawProperty($component, PropertyName::DTSTART);
        }

        try {
            $date = (new DateTimeMapper())->value($recurrenceId, $timezone->getName())
                ?? throw new UnsupportedRecurrence('A RECURRENCE-ID cannot be resolved safely.');

            return 'T:' . $date->format('U.u');
        } catch (InvalidDataException $exception) {
            throw new UnsupportedRecurrence('A RECURRENCE-ID cannot be resolved safely.', $exception);
        }
    }

    /** Distinguish a real point event from an event requiring an endpoint. */
    private function requiresEnd(Event $event): bool
    {
        return $event->startIsDate || $event->hasProperty(PropertyName::DTEND) || $event->hasProperty(PropertyName::DURATION);
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
