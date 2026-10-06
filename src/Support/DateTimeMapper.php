<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Mattmy\ICalendar\CalendarIssue;
use Sabre\VObject\Component as SabreComponent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property as SabreProperty;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Recur\RRuleIterator;

/** Map RFC date-time forms without substituting host timezone definitions. */
final class DateTimeMapper
{
    private const MAX_OBSERVANCE_TRANSITIONS = 3500;

    /** Convert the first date or date-time value to an immutable snapshot. */
    public function value(?SabreProperty $property, string $floatingTimezone): ?CarbonImmutable
    {
        if (! $property instanceof DateTimeProperty) {
            return null;
        }

        return $this->values($property, $floatingTimezone)[0] ?? null;
    }

    /** Apply nominal calendar days before accurate elapsed time using the source timezone. */
    public function durationEnd(?SabreProperty $start, DateInterval $duration, string $floatingTimezone): ?CarbonImmutable
    {
        if (! $start instanceof DateTimeProperty) {
            return null;
        }

        $value = $this->value($start, $floatingTimezone);

        if ($value === null) {
            return null;
        }

        if ($duration->d !== 0) {
            $local = CarbonImmutable::instance($this->isDate($start)
                ? DateTimeParser::parseDate((string) $start)
                : DateTimeParser::parseDateTime((string) $start))->addDays($duration->d);
            $endpoint = clone $start;
            $endpoint->setValue($local->format($this->isDate($start) ? 'Ymd' : 'Ymd\THis')
                . (\str_ends_with((string) $start, 'Z') ? 'Z' : ''));

            try {
                $value = $this->value($endpoint, $floatingTimezone);
            } catch (InvalidDataException) {
                return null;
            }

            if ($value === null) {
                return null;
            }
        }

        $seconds = $duration->h * 3600 + $duration->i * 60 + $duration->s;

        if ($seconds === 0) {
            return $value;
        }

        $instant = $value->utc()->addSeconds($seconds);
        $tzid = $start[ParameterName::TZID];

        if (! $tzid instanceof Parameter) {
            return $instant->setTimezone($value->getTimezone());
        }

        $definition = $this->matchingTimezone($start, (string) $tzid);
        $offset = $definition === null ? null : $this->timezoneOffsetAt($definition, $instant->format('Ymd\THis\Z'), true);

        return $offset === null ? null : $instant->setTimezone(new DateTimeZone($offset));
    }

    /**
     * Convert all resolvable values, or return null when TZID cannot be trusted.
     *
     * @return list<CarbonImmutable>|null
     */
    public function values(DateTimeProperty $property, string $floatingTimezone): ?array
    {
        $values = $this->dateTimes($property, $floatingTimezone);

        if ($values === null) {
            return null;
        }

        return \array_map(
            static fn (DateTimeInterface $value): CarbonImmutable => CarbonImmutable::instance($value),
            $values,
        );
    }

    /** Determine whether a date property has floating semantics. */
    public function isFloating(?SabreProperty $property): bool
    {
        if (! $property instanceof DateTimeProperty) {
            return false;
        }

        if ($property->getValueType() === 'DATE') {
            return true;
        }

        return $property[ParameterName::TZID] === null
            && ! \str_ends_with(\strtoupper($property->getRawMimeDirValue()), 'Z');
    }

    /** Determine whether a property uses the iCalendar DATE value type. */
    public function isDate(?SabreProperty $property): bool
    {
        return $property instanceof DateTimeProperty && $property->getValueType() === 'DATE';
    }

    /**
     * Report date-time properties whose TZID cannot be resolved without guessing.
     *
     * @return list<CalendarIssue>
     */
    public function issues(SabreComponent $component): array
    {
        $issues = [];

        foreach ($component->children() as $child) {
            if ($child instanceof DateTimeProperty && ! $this->hasResolvableTimezone($child)) {
                $issues[] = new CalendarIssue(
                    level: CalendarIssue::LEVEL_WARNING,
                    code: 'mapping_warning',
                    message: 'A date-time property uses a TZID that could not be resolved reliably.',
                    source: 'mapping',
                    component: $component->name,
                    property: $child->name,
                );
            } elseif ($child instanceof SabreComponent) {
                $issues = [...$issues, ...$this->issues($child)];
            }
        }

        return $issues;
    }

    /** Determine whether a date-time property has a matching calendar timezone. */
    private function hasResolvableTimezone(DateTimeProperty $property): bool
    {
        $parameter = $property[ParameterName::TZID];

        if ($parameter === null) {
            return true;
        }

        if (! $parameter instanceof Parameter) {
            return false;
        }

        $timezone = $parameter->getValue();

        if (! \is_string($timezone)) {
            return false;
        }

        $definition = $this->matchingTimezone($property, $timezone);

        if ($definition === null) {
            return false;
        }

        foreach ($property->getParts() as $part) {
            if ($this->timezoneOffsetAt($definition, ParserValue::text($part)) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve all date-time values using the calendar definition when present.
     *
     * @return list<DateTimeInterface>|null
     */
    private function dateTimes(DateTimeProperty $property, string $floatingTimezone): ?array
    {
        $parameter = $property[ParameterName::TZID];

        if ($parameter === null) {
            return \array_values(\array_filter(
                $property->getDateTimes(new DateTimeZone($floatingTimezone)),
                static fn (mixed $value): bool => $value instanceof DateTimeInterface,
            ));
        }

        if (! $parameter instanceof Parameter) {
            return null;
        }

        $timezone = $parameter->getValue();

        if (! \is_string($timezone)) {
            return null;
        }

        $definition = $this->matchingTimezone($property, $timezone);

        if ($definition === null) {
            return null;
        }

        $values = [];

        foreach ($property->getParts() as $part) {
            $raw = ParserValue::text($part);
            $offset = $this->timezoneOffsetAt($definition, $raw);

            if ($offset === null) {
                return null;
            }

            $resolved = new DateTimeZone($offset);

            try {
                $candidate = new DateTimeZone($timezone);
                $candidateValue = DateTimeParser::parseDateTime($raw, $candidate);

                if ($candidateValue->format('P') === $offset && $candidateValue->format('Ymd\THis') === $raw) {
                    $resolved = $candidate;
                }
            } catch (Exception) {
                // The calendar offset remains authoritative when no equivalent host zone exists.
            }

            $values[] = DateTimeParser::parseDateTime($raw, $resolved);
        }

        return $values;
    }

    /** Find the same-calendar VTIMEZONE definition for a TZID. */
    private function matchingTimezone(DateTimeProperty $property, string $timezone): ?SabreComponent
    {
        $root = $property->parent;

        while ($root?->parent !== null) {
            $root = $root->parent;
        }

        if (! $root instanceof VCalendar) {
            return null;
        }

        foreach ($root->select('VTIMEZONE') as $definition) {
            if ($definition instanceof SabreComponent
                && (string) ($this->property($definition, 'TZID') ?? '') === $timezone) {
                return $definition;
            }
        }

        return null;
    }

    /** Resolve an observance offset for a local wall clock or an accurate UTC instant. */
    private function timezoneOffsetAt(SabreComponent $definition, string $raw, bool $utc = false): ?string
    {
        try {
            $target = DateTimeParser::parseDateTime($raw);
        } catch (InvalidDataException) {
            return null;
        }

        $effectiveAt = null;
        $effectiveOffset = null;
        $initialAt = null;
        $initialOffset = null;

        foreach ($definition->children() as $observance) {
            if (! $observance instanceof SabreComponent || ! \in_array($observance->name, ['STANDARD', 'DAYLIGHT'], true)) {
                continue;
            }

            $startProperty = $this->property($observance, PropertyName::DTSTART);
            $offsetTo = $this->property($observance, 'TZOFFSETTO');
            $offsetFrom = $this->property($observance, 'TZOFFSETFROM');

            if (! $startProperty instanceof DateTimeProperty || $offsetTo === null || $offsetFrom === null) {
                continue;
            }

            try {
                $start = DateTimeParser::parseDateTime((string) $startProperty);
            } catch (InvalidDataException) {
                continue;
            }

            $transitions = [$start];
            $fromSeconds = $this->offsetSeconds((string) $offsetFrom);
            $toSeconds = $this->offsetSeconds((string) $offsetTo);
            // Local gaps use the pre-transition offset; overlaps use their first occurrence.
            $shift = $utc ? -$fromSeconds : 0;
            $targetTimestamp = $target->getTimestamp();

            if ($initialAt === null || $start->getTimestamp() + $shift < $initialAt) {
                $initialAt = $start->getTimestamp() + $shift;
                $initialOffset = $this->normalizeUtcOffset((string) $offsetFrom);
            }

            foreach ($observance->select(PropertyName::RRULE) as $rule) {
                if (! $rule instanceof SabreProperty || $start->getTimestamp() + $shift > $targetTimestamp) {
                    continue;
                }

                try {
                    $parts = ParserValue::recurrenceParts($rule);
                    $until = $parts['UNTIL'] ?? null;
                    unset($parts['UNTIL']);
                    $cutoff = null;

                    if (\is_string($until)) {
                        $from = $this->normalizeUtcOffset((string) $offsetFrom);

                        if ($from === null) {
                            return null;
                        }

                        // Compare the naive wall clock against UNTIL expressed using the pre-transition offset.
                        $cutoff = DateTimeParser::parseDateTime($until)
                            ->setTimezone(new DateTimeZone($from))->format('Ymd\THis');
                    }

                    $iterator = new RRuleIterator($parts, $start);
                    $count = 0;

                    while ($iterator->valid() && $count++ < self::MAX_OBSERVANCE_TRANSITIONS) {
                        $transition = $iterator->current();

                        if (! $transition instanceof DateTimeInterface || $transition->getTimestamp() + $shift > $targetTimestamp
                            || ($cutoff !== null && $transition->format('Ymd\THis') > $cutoff)) {
                            break;
                        }

                        $transitions[] = $transition;
                        $iterator->next();
                    }

                    $next = $iterator->current();

                    if ($next instanceof DateTimeInterface && $next->getTimestamp() + $shift <= $targetTimestamp
                        && ($cutoff === null || $next->format('Ymd\THis') <= $cutoff)) {
                        return null;
                    }
                } catch (InvalidDataException) {
                    continue;
                }
            }

            foreach ($observance->select(PropertyName::RDATE) as $date) {
                if ($date instanceof DateTimeProperty) {
                    $transitions = [...$transitions, ...$date->getDateTimes(new DateTimeZone('UTC'))];
                }
            }

            foreach ($transitions as $transition) {
                if (! $transition instanceof DateTimeInterface) {
                    continue;
                }

                $at = $transition->getTimestamp() + $shift;

                if ($at <= $targetTimestamp && ($effectiveAt === null || $at > $effectiveAt)) {
                    $effectiveAt = $at;
                    $inGap = ! $utc && $targetTimestamp < $at + \max(0, $toSeconds - $fromSeconds);
                    $effectiveOffset = $this->normalizeUtcOffset((string) ($inGap ? $offsetFrom : $offsetTo));
                }
            }
        }

        return $effectiveAt !== null ? $effectiveOffset : $initialOffset;
    }

    /** Express a validated RFC offset as seconds for transition boundary comparisons. */
    private function offsetSeconds(string $offset): int
    {
        $seconds = (int) \substr($offset, 1, 2) * 3600 + (int) \substr($offset, 3, 2) * 60 + (int) \substr($offset, 5, 2);

        return \str_starts_with($offset, '-') ? -$seconds : $seconds;
    }

    /** Return the first direct property with the requested name. */
    private function property(SabreComponent $component, string $name): ?SabreProperty
    {
        $property = $component->select($name)[0] ?? null;

        return $property instanceof SabreProperty ? $property : null;
    }

    /** Convert RFC UTC-OFFSET syntax to a PHP fixed-offset timezone name. */
    private function normalizeUtcOffset(string $offset): ?string
    {
        if (! \preg_match('/^([+-])(\d{2})(\d{2})(\d{2})?$/', $offset, $parts)) {
            return null;
        }

        if (($parts[4] ?? '00') !== '00') {
            return null;
        }

        return $parts[1] . $parts[2] . ':' . $parts[3];
    }
}
