<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Sabre\VObject\Component\VTodo;

/** Represent an immutable typed view of one VTODO component. */
final readonly class Todo
{
    /**
     * Hydrate an immutable todo snapshot.
     *
     * @param  list<Property>  $propertyItems
     *
     * @internal
     */
    public function __construct(
        public ?string $uid,
        public ?CarbonImmutable $timestamp,
        public ?string $classification,
        public ?CarbonImmutable $completedAt,
        public ?CarbonImmutable $createdAt,
        public ?string $description,
        public ?CarbonImmutable $startsAt,
        /** Whether DTSTART uses the iCalendar DATE value type. */
        public bool $startIsDate,
        /** Whether DTSTART has DATE or floating DATE-TIME semantics. */
        public bool $startIsFloating,
        /** The explicit or duration-derived task due value. */
        public ?CarbonImmutable $dueAt,
        /** Whether DUE, or its derived value, uses DATE semantics. */
        public bool $dueIsDate,
        /** Whether DUE, or its derived value, has floating semantics. */
        public bool $dueIsFloating,
        /** The effective duration; this mutable value is isolated from parser state. */
        public ?DateInterval $duration,
        public ?CarbonImmutable $lastModifiedAt,
        public ?string $location,
        public ?Organizer $organizer,
        public ?int $percentComplete,
        public ?int $priority,
        public ?CarbonImmutable $recurrenceId,
        /** Whether RECURRENCE-ID uses the iCalendar DATE value type. */
        public bool $recurrenceIdIsDate,
        /** Whether RECURRENCE-ID has DATE or floating DATE-TIME semantics. */
        public bool $recurrenceIdIsFloating,
        public ?int $sequence,
        public ?string $status,
        public ?string $summary,
        public ?string $url,
        /** @var Collection<int, Attendee> */
        public Collection $attendees,
        /** @var Collection<int, string> */
        public Collection $categories,
        /** @var Collection<int, Alarm> */
        public Collection $alarms,
        private array $propertyItems,
        private VTodo $component,
    ) {}

    /** Return a deep clone of the underlying low-level todo component. */
    public function rawComponent(): VTodo
    {
        return clone $this->component;
    }

    /**
     * Return direct todo properties, optionally filtered case-insensitively by name.
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

        $name = \strtoupper(\trim($name));

        if ($name === '') {
            throw new InvalidArgumentException('Property names must not be empty.');
        }

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
}
