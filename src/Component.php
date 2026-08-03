<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Sabre\VObject\Component as SabreComponent;

final readonly class Component
{
    /**
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

    /** @return Collection<int, Property> */
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

    public function hasProperty(?string $name = null): bool
    {
        return $this->properties($name)->isNotEmpty();
    }

    public function property(string $name): ?Property
    {
        return $this->properties($name)->first();
    }

    /** @return Collection<int, Component> */
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

    public function rawComponent(): SabreComponent
    {
        return clone $this->component;
    }

    private static function normalizeName(string $name, string $kind): string
    {
        $name = \trim($name);

        if ($name === '') {
            throw new InvalidArgumentException("{$kind} names must not be empty.");
        }

        return \strtoupper($name);
    }
}
