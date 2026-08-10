<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Sabre\VObject\Component\VEvent;

/** Represent an immutable typed view of one VEVENT component. */
final readonly class Event
{
    /**
     * Hydrate an immutable event snapshot.
     *
     * @param  list<Property>  $propertyItems
     *
     * @internal
     */
    public function __construct(
        public ?string $uid,
        public ?string $summary,
        public ?string $description,
        public ?string $location,
        public ?CarbonImmutable $startsAt,
        /** The exclusive event end; all-day DTEND is never reduced by one day. */
        public ?CarbonImmutable $endsAt,
        /** Whether DTSTART uses the iCalendar DATE value type. */
        public bool $allDay,
        /** Whether DTSTART uses the iCalendar DATE value type. */
        public bool $startIsDate,
        /** Whether DTEND, or its derived value, uses DATE semantics. */
        public bool $endIsDate,
        /** Whether DTSTART has DATE or floating DATE-TIME semantics. */
        public bool $startIsFloating,
        /** Whether DTEND, or its derived value, has floating semantics. */
        public bool $endIsFloating,
        /** The inclusive final calendar date for all-day events only. */
        public ?CarbonImmutable $lastDay,
        /** The effective duration; this mutable value is isolated from parser state. */
        public ?DateInterval $duration,
        public ?CarbonImmutable $timestamp,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $lastModifiedAt,
        public ?string $status,
        public ?string $classification,
        public ?int $priority,
        public ?CarbonImmutable $recurrenceId,
        /** Whether RECURRENCE-ID uses the iCalendar DATE value type. */
        public bool $recurrenceIdIsDate,
        /** Whether RECURRENCE-ID has DATE or floating DATE-TIME semantics. */
        public bool $recurrenceIdIsFloating,
        public ?int $sequence,
        public ?string $url,
        public ?Organizer $organizer,
        /** @var Collection<int, Attendee> */
        public Collection $attendees,
        /** @var Collection<int, Alarm> */
        public Collection $alarms,
        /** @var Collection<int, string> */
        public Collection $categories,
        private array $propertyItems,
        private VEvent $component,
    ) {}

    /**
     * Determine whether DTSTART uses the iCalendar DATE value type.
     */
    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    /**
     * Return a deep clone of the underlying low-level event component.
     */
    public function rawComponent(): VEvent
    {
        return clone $this->component;
    }

    /**
     * Return direct event properties, optionally filtered case-insensitively by name.
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
