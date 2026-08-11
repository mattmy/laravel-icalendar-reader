<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Mattmy\ICalendar\Concerns\QueriesProperties;
use Sabre\VObject\Component as SabreComponent;

/** Represent an immutable generic view of an untyped iCalendar component. */
final readonly class Component
{
    use QueriesProperties;

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
     * Return the generic component's ordered direct properties for the internal query trait.
     *
     * @return list<Property>
     *
     * @internal
     */
    protected function propertyItems(): array
    {
        return $this->propertyItems;
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
