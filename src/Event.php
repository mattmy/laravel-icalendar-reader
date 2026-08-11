<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Support\Collection;
use Mattmy\ICalendar\Concerns\QueriesProperties;
use Sabre\VObject\Component\VEvent;

/** Represent an immutable typed view of one VEVENT component. */
final readonly class Event
{
    use QueriesProperties;

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
        /** @var array{latitude: float, longitude: float}|null */
        public ?array $geo,
        public ?string $transparency,
        /** @var Collection<int, string> */
        public Collection $comments,
        /** @var Collection<int, string> */
        public Collection $contacts,
        /** @var Collection<int, string> */
        public Collection $resources,
        public ?Property $recurrenceRule,
        /** @var Collection<int, Property> */
        public Collection $attachments,
        /** @var Collection<int, Property> */
        public Collection $exceptionDates,
        /** @var Collection<int, Property> */
        public Collection $requestStatuses,
        /** @var Collection<int, Property> */
        public Collection $relatedTo,
        /** @var Collection<int, Property> */
        public Collection $recurrenceDates,
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
     * Return the event's ordered direct properties for the internal query trait.
     *
     * @return list<Property>
     *
     * @internal
     */
    protected function propertyItems(): array
    {
        return $this->propertyItems;
    }
}
