<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Illuminate\Support\Collection;

/** Represent an immutable typed view of an ATTENDEE property. */
final readonly class Attendee
{
    /**
     * Hydrate an attendee from one cal-address and its normalized parameters.
     *
     * @param  array<string, string|list<string>>  $parameterItems
     *
     * @internal
     */
    public function __construct(
        public string $address,
        public ?string $email,
        public ?string $name,
        public ?string $role,
        public ?string $status,
        public ?bool $rsvp,
        public ?string $type,
        /** @var Collection<int, string> */
        public Collection $delegatedFrom,
        /** @var Collection<int, string> */
        public Collection $delegatedTo,
        private array $parameterItems,
    ) {}

    /**
     * Return every attendee parameter without discarding multi-value entries.
     *
     * @return array<string, string|list<string>>
     */
    public function parameters(): array
    {
        return $this->parameterItems;
    }
}
