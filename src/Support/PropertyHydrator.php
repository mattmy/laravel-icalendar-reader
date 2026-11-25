<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use DateInterval;
use Exception;
use LogicException;
use Mattmy\ICalendar\CalendarIssue;
use Mattmy\ICalendar\Property;
use Sabre\VObject\Component as SabreComponent;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property as SabreProperty;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Property\ICalendar\Duration as DurationProperty;

/**
 * Hydrate ordered iCalendar properties without collapsing repeated data.
 *
 * @phpstan-import-type StructuredValue from Property
 * @phpstan-import-type PropertyAtom from Property
 */
final readonly class PropertyHydrator
{
    /** Create the property hydrator with the shared date-time mapper. */
    public function __construct(
        private DateTimeMapper $dateTimeMapper,
    ) {}

    /**
     * Hydrate all direct properties in document order and append mapping warnings when requested.
     *
     * @param  (callable(CalendarIssue): void)|null  $warn
     * @return list<Property>
     */
    public function hydrate(SabreComponent $component, string $floatingTimezone, ?callable $warn = null): array
    {
        $properties = [];

        foreach ($component->children() as $child) {
            if ($child instanceof SabreProperty) {
                $properties[] = $child;
            }
        }

        \usort($properties, static fn (SabreProperty $left, SabreProperty $right): int => ($left->lineIndex ?? \PHP_INT_MAX) <=> ($right->lineIndex ?? \PHP_INT_MAX));

        $mappedProperties = [];

        foreach ($properties as $property) {
            $mapped = $this->hydrateProperty($property, $floatingTimezone);
            $mappedProperties[] = $mapped;

            if ($warn !== null && $mapped->type === 'duration' && $mapped->values === []) {
                $warn(new CalendarIssue(
                    level: CalendarIssue::LEVEL_WARNING,
                    code: 'mapping_warning',
                    message: 'The duration property could not be represented as a typed value.',
                    source: 'mapping',
                    component: $component->name,
                    property: $mapped->name,
                ));
            }
        }

        return $mappedProperties;
    }

    /**
     * Normalize all property parameters while preserving multi-values.
     *
     * @return array<string, string|list<string>>
     */
    public function parameters(SabreProperty $property): array
    {
        $parameters = [];

        foreach ($property->parameters() as $parameter) {
            if (! $parameter instanceof Parameter) {
                throw new LogicException('Sabre returned an invalid property parameter.');
            }

            $parts = \array_values(\array_map(
                static fn (mixed $part): string => ParserValue::text($part),
                $parameter->getParts(),
            ));
            $parameters[\strtoupper((string) $parameter->name)] = \count($parts) === 1
                ? $parts[0]
                : $parts;
        }

        return $parameters;
    }

    /** Hydrate one property with typed, raw, and parameter representations. */
    private function hydrateProperty(SabreProperty $property, string $floatingTimezone): Property
    {
        if ($property->name === null || \trim($property->name) === '') {
            throw new LogicException('Sabre returned a property without a name.');
        }

        $values = $this->propertyValues($property, $floatingTimezone);

        $value = match (\count($values)) {
            0 => null,
            1 => $values[0],
            default => $values,
        };

        return new Property(
            name: \strtoupper($property->name),
            type: \strtolower($property->getValueType()),
            value: $value,
            values: $values,
            parameterItems: $this->parameters($property),
            rawValue: $property->getRawMimeDirValue(),
        );
    }

    /**
     * Convert known values while retaining unresolved or unknown values as text.
     *
     * @return list<PropertyAtom>
     */
    private function propertyValues(SabreProperty $property, string $floatingTimezone): array
    {
        if ($property instanceof DateTimeProperty) {
            $values = $this->dateTimeMapper->values($property, $floatingTimezone);

            return $values ?? \array_values(\array_map(
                static fn (mixed $part): string => ParserValue::text($part),
                $property->getParts(),
            ));
        }

        if ($property instanceof DurationProperty) {
            if (\str_starts_with($property->name ?? '', 'X-') && \count($property->getParts()) > 1) {
                return [];
            }

            $duration = $this->durationValue($property);

            return $duration === null ? [] : [$duration];
        }

        $type = \strtoupper($property->getValueType());
        $parts = $property->getParts();

        if (! \array_is_list($parts)) {
            return [self::structuredPropertyValue($parts)];
        }

        return \array_map(
            static function (mixed $part) use ($type): bool|int|float|string|array {
                if (\is_array($part)) {
                    return self::structuredPropertyValue($part);
                }

                if (\is_bool($part) || \is_int($part) || \is_float($part)) {
                    return $part;
                }

                $part = ParserValue::text($part);

                return match ($type) {
                    'BOOLEAN' => \strtoupper($part) === 'TRUE',
                    'FLOAT' => (float) $part,
                    'INTEGER' => (int) $part,
                    default => $part,
                };
            },
            $parts,
        );
    }

    /** Map a validated duration only when every numeric field can be represented exactly. */
    private function durationValue(DurationProperty $property): ?DateInterval
    {
        try {
            $duration = $property->getDateInterval();
        } catch (Exception) {
            return null;
        }

        $raw = ParserValue::text($property->getParts()[0] ?? null);
        \preg_match_all('/([0-9]+)([WDHMS])/', $raw, $parts, \PREG_SET_ORDER);

        foreach ($parts as [, $digits, $unit]) {
            $mapped = match ($unit) {
                'W' => \intdiv($duration->d, 7),
                'D' => $duration->d,
                'H' => $duration->h,
                'M' => $duration->i,
                'S' => $duration->s,
            };

            if ((string) $mapped !== (\ltrim($digits, '0') ?: '0')) {
                return null;
            }
        }

        return $duration;
    }

    /**
     * Normalize one structured parser value without discarding named parts.
     *
     * @param  array<array-key, mixed>  $value
     * @return StructuredValue
     */
    private static function structuredPropertyValue(array $value): array
    {
        $structured = [];

        foreach ($value as $key => $item) {
            $structured[(string) $key] = \is_array($item)
                ? \array_values(\array_map(static fn (mixed $part): string => ParserValue::text($part), $item))
                : ParserValue::text($item);
        }

        return $structured;
    }
}
