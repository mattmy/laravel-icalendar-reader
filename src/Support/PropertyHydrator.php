<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use LogicException;
use Mattmy\ICalendar\Property;
use Sabre\VObject\Component as SabreComponent;
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
     * Hydrate all direct properties in document order.
     *
     * @return list<Property>
     */
    public function hydrate(SabreComponent $component, string $floatingTimezone): array
    {
        $properties = [];

        foreach ($component->children() as $child) {
            if ($child instanceof SabreProperty) {
                $properties[] = $child;
            }
        }

        \usort($properties, static fn (SabreProperty $left, SabreProperty $right): int => ($left->lineIndex ?? \PHP_INT_MAX) <=> ($right->lineIndex ?? \PHP_INT_MAX));

        return \array_map(
            fn (SabreProperty $property): Property => $this->hydrateProperty($property, $floatingTimezone),
            $properties,
        );
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
            $parts = \array_values(\array_map(
                static fn (mixed $part): string => (string) $part,
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
                static fn (mixed $part): string => (string) $part,
                $property->getParts(),
            ));
        }

        if ($property instanceof DurationProperty) {
            return [$property->getDateInterval()];
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

                return match ($type) {
                    'BOOLEAN' => \strtoupper((string) $part) === 'TRUE',
                    'FLOAT' => (float) $part,
                    'INTEGER' => (int) $part,
                    default => (string) $part,
                };
            },
            $parts,
        );
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
                ? \array_values(\array_map(static fn (mixed $part): string => (string) $part, $item))
                : (string) $item;
        }

        return $structured;
    }
}
