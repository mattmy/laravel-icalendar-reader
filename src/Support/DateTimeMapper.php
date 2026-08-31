<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
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

    /**
     * Convert all resolvable values, or return null when TZID cannot be trusted.
     *
     * @return list<CarbonImmutable>|null
     */
    public function values(DateTimeProperty $property, string $floatingTimezone): ?array
    {
        if (! $this->hasResolvableTimezone($property)) {
            return null;
        }

        return \array_map(
            static fn (DateTimeInterface $value): CarbonImmutable => CarbonImmutable::instance($value),
            $this->dateTimes($property, $floatingTimezone),
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
            if ($this->timezoneOffsetAt($definition, (string) $part) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve all date-time values using the calendar definition when present.
     *
     * @return list<DateTimeInterface>
     */
    private function dateTimes(DateTimeProperty $property, string $floatingTimezone): array
    {
        $parameter = $property[ParameterName::TZID];

        if (! $parameter instanceof Parameter) {
            return \array_values(\array_filter(
                $property->getDateTimes(new DateTimeZone($floatingTimezone)),
                static fn (mixed $value): bool => $value instanceof DateTimeInterface,
            ));
        }

        $timezone = $parameter->getValue();

        if (! \is_string($timezone)) {
            return [];
        }

        $definition = $this->matchingTimezone($property, $timezone);

        if ($definition === null) {
            return [];
        }

        $values = [];

        foreach ($property->getParts() as $part) {
            $raw = (string) $part;
            $offset = $this->timezoneOffsetAt($definition, $raw);

            if ($offset === null) {
                return [];
            }

            $resolved = new DateTimeZone($offset);

            try {
                $candidate = new DateTimeZone($timezone);

                if (DateTimeParser::parseDateTime($raw, $candidate)->format('P') === $offset) {
                    $resolved = $candidate;
                }
            } catch (\Exception) {
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
            if ($definition instanceof SabreComponent && (string) ($definition->TZID ?? '') === $timezone) {
                return $definition;
            }
        }

        return null;
    }

    /** Resolve the effective observance offset for one local date-time. */
    private function timezoneOffsetAt(SabreComponent $definition, string $raw): ?string
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

            if ($initialAt === null || $start < $initialAt) {
                $initialAt = $start;
                $initialOffset = $this->normalizeUtcOffset((string) $offsetFrom);
            }

            $transitions = [$start];

            foreach ($observance->select(PropertyName::RRULE) as $rule) {
                if (! $rule instanceof SabreProperty) {
                    continue;
                }

                try {
                    $iterator = new RRuleIterator($rule->getParts(), $start);
                    $count = 0;

                    while ($iterator->valid() && $count++ < self::MAX_OBSERVANCE_TRANSITIONS) {
                        $transition = $iterator->current();

                        if (! $transition instanceof DateTimeInterface || $transition > $target) {
                            break;
                        }

                        $transitions[] = $transition;
                        $iterator->next();
                    }

                    if ($iterator->valid() && $iterator->current() <= $target) {
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
                if ($transition <= $target && ($effectiveAt === null || $transition > $effectiveAt)) {
                    $effectiveAt = $transition;
                    $effectiveOffset = $this->normalizeUtcOffset((string) $offsetTo);
                }
            }
        }

        return $effectiveOffset ?? $initialOffset;
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
