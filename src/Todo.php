<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Support\Collection;
use Mattmy\ICalendar\Concerns\QueriesProperties;
use Sabre\VObject\Component\VTodo;

/** Represent an immutable typed view of one VTODO component. */
final readonly class Todo
{
    use QueriesProperties;

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
        /** @var array{latitude: float, longitude: float}|null */
        public ?array $geo,
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
        private VTodo $component,
    ) {}

    /** Return a deep clone of the underlying low-level todo component. */
    public function rawComponent(): VTodo
    {
        return clone $this->component;
    }

    /**
     * Return the todo's ordered direct properties for the internal query trait.
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
