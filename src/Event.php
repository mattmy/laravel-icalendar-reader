<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Sabre\VObject\Component\VEvent;

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
        public ?CarbonImmutable $endsAt,
        public bool $startIsFloating,
        public bool $endIsFloating,
        public ?CarbonImmutable $lastDay,
        public ?DateInterval $duration,
        public ?CarbonImmutable $timestamp,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $lastModifiedAt,
        public ?string $status,
        public ?string $classification,
        public ?int $priority,
        public ?int $sequence,
        public ?string $url,
        public ?Organizer $organizer,
        /** @var Collection<int, Attendee> */
        public Collection $attendees,
        /** @var Collection<int, Alarm> */
        public Collection $alarms,
        /** @var Collection<int, string> */
        public Collection $categories,
        private bool $allDay,
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

    /** @return Collection<int, Property> */
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

    public function hasProperty(?string $name = null): bool
    {
        return $this->properties($name)->isNotEmpty();
    }

    public function property(string $name): ?Property
    {
        return $this->properties($name)->first();
    }
}
