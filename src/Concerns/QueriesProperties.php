<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Concerns;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Mattmy\ICalendar\Property;

/** @internal Provide shared direct-property queries for calendar snapshots. */
trait QueriesProperties
{
    /**
     * Return direct properties, optionally filtered case-insensitively by name.
     *
     * @return Collection<int, Property>
     *
     * @throws InvalidArgumentException
     */
    public function properties(?string $name = null): Collection
    {
        $properties = collect($this->propertyItems());

        if ($name === null) {
            return $properties;
        }

        $name = self::normalizePropertyName($name);

        return $properties
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
        $name = $name === null ? null : self::normalizePropertyName($name);

        foreach ($this->propertyItems() as $property) {
            if ($name === null || $property->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the first direct property matching a case-insensitive name.
     *
     * @throws InvalidArgumentException
     */
    public function property(string $name): ?Property
    {
        $name = self::normalizePropertyName($name);

        foreach ($this->propertyItems() as $property) {
            if ($property->name === $name) {
                return $property;
            }
        }

        return null;
    }

    /**
     * Return the consuming snapshot's ordered direct properties.
     *
     * @return list<Property>
     */
    abstract protected function propertyItems(): array;

    /**
     * Normalize and validate a direct property name.
     *
     * @throws InvalidArgumentException
     */
    private static function normalizePropertyName(string $name): string
    {
        $name = \strtoupper(\trim($name));

        if ($name === '') {
            throw new InvalidArgumentException('Property names must not be empty.');
        }

        return $name;
    }
}
