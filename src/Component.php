<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Sabre\VObject\Component as SabreComponent;

/** Represent an immutable generic view of an untyped iCalendar component. */
final readonly class Component
{
    /**
     * Hydrate a generic component and its ordered direct children.
     *
     * @param  list<Property>  $propertyItems
     * @param  list<Component>  $componentItems
     * @internal
     */
    public function __construct(
        public string $name,
        private array $propertyItems,
        private array $componentItems,
        private SabreComponent $component,
    ) {}

    /**
     * Return direct properties, optionally filtered case-insensitively by name.
     *
     * @return Collection<int, Property>
     *
     * @throws InvalidArgumentException
     */
    public function properties(?string $name = null): Collection
    {
        if ($name === null) {
            return collect($this->propertyItems);
        }

        $name = self::normalizeName($name, 'Property');

        return collect($this->propertyItems)
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
        return $this->properties($name)->isNotEmpty();
    }

    /**
     * Return the first direct property matching a case-insensitive name.
     *
     * @throws InvalidArgumentException
     */
    public function property(string $name): ?Property
    {
        return $this->properties($name)->first();
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

        $name = self::normalizeName($name, 'Component');

        return collect($this->componentItems)
            ->filter(static fn (self $component): bool => $component->name === $name)
            ->values();
    }

    /** Return a deep clone of the underlying low-level component. */
    public function rawComponent(): SabreComponent
    {
        return clone $this->component;
    }

    /**
     * Normalize and validate an iCalendar property or component name.
     *
     * @throws InvalidArgumentException
     */
    private static function normalizeName(string $name, string $kind): string
    {
        $name = \trim($name);

        if ($name === '') {
            throw new InvalidArgumentException("{$kind} names must not be empty.");
        }

        return \strtoupper($name);
    }
}
