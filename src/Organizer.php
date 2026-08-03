<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

final readonly class Organizer
{
    /**
     * @param  array<string, string|list<string>>  $parameterItems
     * @internal
     */
    public function __construct(
        public string $address,
        public ?string $email,
        public ?string $name,
        public ?string $sentBy,
        public ?string $directory,
        private array $parameterItems,
    ) {}

    /**
     * Return every organizer parameter without discarding multi-value entries.
     *
     * @return array<string, string|list<string>>
     */
    public function parameters(): array
    {
        return $this->parameterItems;
    }
}
