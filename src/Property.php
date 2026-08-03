<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use InvalidArgumentException;

final readonly class Property
{
    /**
     * @param  list<mixed>  $values
     * @param  array<string, string|list<string>>  $parameterItems
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

    /** @return array<string, string|list<string>> */
    public function parameters(): array
    {
        return $this->parameterItems;
    }

    /** @return string|list<string>|null */
    public function parameter(string $name): string|array|null
    {
        $name = self::normalizeName($name);

        return $this->parameterItems[$name] ?? null;
    }

    public function rawValue(): string
    {
        return $this->rawValue;
    }

    private static function normalizeName(string $name): string
    {
        $name = \trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Property and parameter names must not be empty.');
        }

        return \strtoupper($name);
    }
}
