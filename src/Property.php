<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateInterval;
use InvalidArgumentException;

/**
 * Represent one ordered iCalendar property without discarding values or parameters.
 *
 * @phpstan-type StructuredValue array<array-key, string|list<string>>
 * @phpstan-type PropertyAtom bool|int|float|string|CarbonImmutable|DateInterval|StructuredValue
 * @phpstan-type PropertyValue PropertyAtom|list<PropertyAtom>|null
 * @phpstan-type PropertyArray array{name: string, type: string, value: PropertyValue, values: list<PropertyAtom>, parameters: array<string, string|list<string>>, raw_value: string}
 */
final readonly class Property
{
    /**
     * Hydrate an immutable property snapshot from normalized parser data.
     *
     * @param  PropertyValue  $value
     * @param  list<PropertyAtom>  $values
     * @param  array<string, string|list<string>>  $parameterItems
     *
     * @internal
     */
    public function __construct(
        public string $name,
        public string $type,
        /** @var PropertyValue */
        public mixed $value,
        /** @var list<PropertyAtom> */
        public array $values,
        private array $parameterItems,
        private string $rawValue,
    ) {}

    /**
     * Return every normalized parameter without discarding multi-value entries.
     *
     * @return array<string, string|list<string>>
     */
    public function parameters(): array
    {
        return $this->parameterItems;
    }

    /**
     * Return a parameter by case-insensitive name.
     *
     * @return string|list<string>|null
     *
     * @throws InvalidArgumentException
     */
    public function parameter(string $name): string|array|null
    {
        $name = self::normalizeName($name);

        return $this->parameterItems[$name] ?? null;
    }

    /** Return Sabre's decoded but otherwise untyped property value. */
    public function rawValue(): string
    {
        return $this->rawValue;
    }

    /**
     * Export the complete normalized property representation.
     *
     * @return PropertyArray
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->value,
            'values' => $this->values,
            'parameters' => $this->parameters(),
            'raw_value' => $this->rawValue(),
        ];
    }

    /**
     * Normalize and validate a property or parameter name.
     *
     * @throws InvalidArgumentException
     */
    private static function normalizeName(string $name): string
    {
        $name = \trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Property and parameter names must not be empty.');
        }

        return \strtoupper($name);
    }
}
