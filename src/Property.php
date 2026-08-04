<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateInterval;
use InvalidArgumentException;

/** Represent one ordered iCalendar property without discarding values or parameters. */
final readonly class Property
{
    /**
     * Hydrate an immutable property snapshot from normalized parser data.
     *
     * @param  bool|int|float|string|CarbonImmutable|DateInterval|list<bool|int|float|string|CarbonImmutable|DateInterval>|null  $value
     * @param  list<bool|int|float|string|CarbonImmutable|DateInterval>  $values
     * @param  array<string, string|list<string>>  $parameterItems
     *
     * @internal
     */
    public function __construct(
        public string $name,
        public string $type,
        public mixed $value,
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
